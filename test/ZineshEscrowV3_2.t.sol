// SPDX-License-Identifier: UNLICENSED
pragma solidity 0.8.30;

import {ZineshEscrowV3_2} from "../contracts/ZineshEscrowV3_2.sol";

interface Vm {
    function deal(address account, uint256 balance) external;
    function expectRevert(bytes4 revertData) external;
    function prank(address sender) external;
    function warp(uint256 newTimestamp) external;
}

contract ZineshEscrowV3_2Test {
    Vm private constant vm = Vm(address(uint160(uint256(keccak256("hevm cheat code")))));
    uint256 private constant DAY = 1 days;
    uint128 private constant AMOUNT = 0.01 ether;
    bytes32 private constant TERMS_HASH = keccak256("Zinesh V3.2 test terms");
    bytes32 private constant PLAN_HASH = keccak256("Zinesh V3.2 test plan");
    bytes32 private constant EVIDENCE_HASH = keccak256("Zinesh V3.2 test evidence");

    address private constant BUYER = address(0xB001);
    address private constant SELLER = address(0x5E11);
    address private constant RESOLVER = address(0xAE501);
    address private constant BACKUP_RESOLVER = address(0xBAC01);
    address private constant OUTSIDER = address(0x0A751DE);

    ZineshEscrowV3_2 private escrow;

    function setUp() public {
        escrow = new ZineshEscrowV3_2();
        vm.deal(BUYER, 100 ether);
        vm.deal(SELLER, 100 ether);
        vm.deal(RESOLVER, 100 ether);
        vm.deal(BACKUP_RESOLVER, 100 ether);
        vm.deal(OUTSIDER, 100 ether);
    }

    function _create(uint64 duration) private returns (uint256 agreementId) {
        vm.prank(BUYER);
        agreementId = escrow.createAgreement(
            SELLER, RESOLVER, BACKUP_RESOLVER, AMOUNT, TERMS_HASH, PLAN_HASH, duration
        );
    }

    function _accept(uint256 agreementId) private {
        vm.prank(BUYER);
        escrow.acceptPlan(agreementId);
        vm.prank(SELLER);
        escrow.acceptPlan(agreementId);
    }

    function _fund(uint256 agreementId) private {
        vm.prank(BUYER);
        escrow.fund{value: AMOUNT}(agreementId);
    }

    function _assertState(uint256 agreementId, ZineshEscrowV3_2.State expected) private view {
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        require(agreement.state == expected, "unexpected state");
    }

    function test01CreatesBoundAgreement() public {
        uint256 agreementId = _create(uint64(3 days));
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        require(agreement.buyer == BUYER, "buyer");
        require(agreement.seller == SELLER, "seller");
        require(agreement.resolver == RESOLVER, "resolver");
        require(agreement.backupResolver == BACKUP_RESOLVER, "backup resolver");
        require(agreement.amount == AMOUNT, "amount");
        require(agreement.termsHash == TERMS_HASH && agreement.planHash == PLAN_HASH, "hashes");
        require(agreement.state == ZineshEscrowV3_2.State.Created, "state");
    }

    function test02RejectsInvalidActorBindings() public {
        vm.expectRevert(ZineshEscrowV3_2.InvalidAddress.selector);
        vm.prank(BUYER);
        escrow.createAgreement(BUYER, RESOLVER, BACKUP_RESOLVER, AMOUNT, TERMS_HASH, PLAN_HASH, uint64(DAY));

        vm.expectRevert(ZineshEscrowV3_2.InvalidAddress.selector);
        vm.prank(BUYER);
        escrow.createAgreement(SELLER, SELLER, BACKUP_RESOLVER, AMOUNT, TERMS_HASH, PLAN_HASH, uint64(DAY));

        vm.expectRevert(ZineshEscrowV3_2.InvalidAddress.selector);
        vm.prank(BUYER);
        escrow.createAgreement(SELLER, RESOLVER, RESOLVER, AMOUNT, TERMS_HASH, PLAN_HASH, uint64(DAY));
    }

    function test03RejectsInvalidAmountHashAndDuration() public {
        vm.expectRevert(ZineshEscrowV3_2.InvalidAmount.selector);
        vm.prank(BUYER);
        escrow.createAgreement(SELLER, RESOLVER, BACKUP_RESOLVER, 0, TERMS_HASH, PLAN_HASH, uint64(DAY));

        vm.expectRevert(ZineshEscrowV3_2.InvalidHash.selector);
        vm.prank(BUYER);
        escrow.createAgreement(SELLER, RESOLVER, BACKUP_RESOLVER, AMOUNT, bytes32(0), PLAN_HASH, uint64(DAY));

        vm.expectRevert(ZineshEscrowV3_2.InvalidDuration.selector);
        vm.prank(BUYER);
        escrow.createAgreement(SELLER, RESOLVER, BACKUP_RESOLVER, AMOUNT, TERMS_HASH, PLAN_HASH, uint64(DAY - 1));

        vm.expectRevert(ZineshEscrowV3_2.InvalidDuration.selector);
        vm.prank(BUYER);
        escrow.createAgreement(SELLER, RESOLVER, BACKUP_RESOLVER, AMOUNT, TERMS_HASH, PLAN_HASH, uint64(365 days + 1));
    }

    function test04RestrictsAcceptanceAndRejectsDuplicateAcceptance() public {
        uint256 agreementId = _create(uint64(3 days));
        vm.expectRevert(ZineshEscrowV3_2.Unauthorized.selector);
        vm.prank(OUTSIDER);
        escrow.acceptPlan(agreementId);

        vm.prank(BUYER);
        escrow.acceptPlan(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.AlreadyAccepted.selector);
        vm.prank(BUYER);
        escrow.acceptPlan(agreementId);
    }

    function test05ExpiresUnacceptedAgreementAfterWindow() public {
        uint256 agreementId = _create(uint64(3 days));
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.DeadlineNotReached.selector);
        escrow.expireUnaccepted(agreementId);
        vm.warp(agreement.acceptanceDeadline + 1);
        escrow.expireUnaccepted(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Expired);
    }

    function test06AcceptsOnlyAfterBothPartiesAndStartsFundingWindow() public {
        uint256 agreementId = _create(uint64(3 days));
        vm.prank(SELLER);
        escrow.acceptPlan(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Created);
        vm.prank(BUYER);
        escrow.acceptPlan(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        require(agreement.state == ZineshEscrowV3_2.State.Accepted, "accepted");
        require(agreement.fundingDeadline - agreement.acceptedAt == DAY, "funding window");
    }

    function test07FundsOnlyExactAmountFromBuyer() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.Unauthorized.selector);
        vm.prank(OUTSIDER);
        escrow.fund{value: AMOUNT}(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.WrongValue.selector);
        vm.prank(BUYER);
        escrow.fund{value: AMOUNT - 1}(agreementId);
        _fund(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Funded);
        require(address(escrow).balance == AMOUNT, "held amount");
    }

    function test08RejectsLateFunding() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.warp(agreement.fundingDeadline + 1);
        vm.expectRevert(ZineshEscrowV3_2.DeadlinePassed.selector);
        vm.prank(BUYER);
        escrow.fund{value: AMOUNT}(agreementId);
    }

    function test09ExpiresAcceptedButUnfundedAgreement() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.DeadlineNotReached.selector);
        escrow.expireUnfunded(agreementId);
        vm.warp(agreement.fundingDeadline + 1);
        escrow.expireUnfunded(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Expired);
    }

    function test10StartsWorkDurationAtFunding() public {
        uint256 agreementId = _create(uint64(5 days));
        vm.warp(block.timestamp + 12 hours);
        _accept(agreementId);
        vm.warp(block.timestamp + 12 hours);
        _fund(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        require(agreement.workDeadline - agreement.fundedAt == 5 days, "work duration");
    }

    function test11AcceptsSellerEvidenceDuringGrace() public {
        uint256 agreementId = _create(uint64(DAY));
        _accept(agreementId);
        _fund(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.warp(agreement.workDeadline + 12 hours);
        vm.prank(SELLER);
        escrow.submitEvidence(agreementId, EVIDENCE_HASH);
        agreement = escrow.getAgreement(agreementId);
        require(agreement.state == ZineshEscrowV3_2.State.EvidenceSubmitted, "evidence state");
        require(agreement.evidenceHash == EVIDENCE_HASH, "evidence hash");
    }

    function test12RejectsInvalidOrLateEvidence() public {
        uint256 agreementId = _create(uint64(DAY));
        _accept(agreementId);
        _fund(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.Unauthorized.selector);
        vm.prank(BUYER);
        escrow.submitEvidence(agreementId, EVIDENCE_HASH);
        vm.expectRevert(ZineshEscrowV3_2.InvalidHash.selector);
        vm.prank(SELLER);
        escrow.submitEvidence(agreementId, bytes32(0));
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.warp(agreement.workDeadline + DAY + 1);
        vm.expectRevert(ZineshEscrowV3_2.DeadlinePassed.selector);
        vm.prank(SELLER);
        escrow.submitEvidence(agreementId, EVIDENCE_HASH);
    }

    function test13BuyerReleasesAfterEvidence() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        _fund(agreementId);
        vm.prank(SELLER);
        escrow.submitEvidence(agreementId, EVIDENCE_HASH);
        vm.prank(BUYER);
        escrow.release(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Released);
        require(address(escrow).balance == 0, "escrow drained");
    }

    function test14SellerClaimsAfterReviewTimeout() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        _fund(agreementId);
        vm.prank(SELLER);
        escrow.submitEvidence(agreementId, EVIDENCE_HASH);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.DeadlineNotReached.selector);
        vm.prank(SELLER);
        escrow.claimEvidenceReviewTimeout(agreementId);
        vm.warp(agreement.evidenceSubmittedAt + 2 days + 1);
        vm.prank(SELLER);
        escrow.claimEvidenceReviewTimeout(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Released);
        require(address(escrow).balance == 0, "escrow drained");
    }

    function test15BuyerClaimsNoEvidenceTimeout() public {
        uint256 agreementId = _create(uint64(DAY));
        _accept(agreementId);
        _fund(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.DeadlineNotReached.selector);
        vm.prank(BUYER);
        escrow.claimNoEvidenceTimeout(agreementId);
        vm.warp(agreement.workDeadline + DAY + 1);
        vm.prank(BUYER);
        escrow.claimNoEvidenceTimeout(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Refunded);
        require(address(escrow).balance == 0, "escrow drained");
    }

    function test16SupportsMutualRefund() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        _fund(agreementId);
        vm.prank(SELLER);
        escrow.requestRefund(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.Unauthorized.selector);
        vm.prank(SELLER);
        escrow.approveRefund(agreementId);
        vm.prank(BUYER);
        escrow.approveRefund(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Refunded);
        require(address(escrow).balance == 0, "escrow drained");
    }

    function test17RestrictsDisputeAndClearsRefundRequest() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        _fund(agreementId);
        vm.prank(SELLER);
        escrow.requestRefund(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.Unauthorized.selector);
        vm.prank(OUTSIDER);
        escrow.openDispute(agreementId);
        vm.prank(BUYER);
        escrow.openDispute(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        require(agreement.state == ZineshEscrowV3_2.State.Disputed, "disputed");
        require(agreement.refundRequester == address(0), "refund requester reset");
    }

    function test18PrimaryResolverHasFirstSevenDays() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        _fund(agreementId);
        vm.prank(BUYER);
        escrow.openDispute(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.Unauthorized.selector);
        vm.prank(BACKUP_RESOLVER);
        escrow.resolveDispute(agreementId, 0);
        vm.expectRevert(ZineshEscrowV3_2.InvalidDecision.selector);
        vm.prank(RESOLVER);
        escrow.resolveDispute(agreementId, 2);
        vm.prank(RESOLVER);
        escrow.resolveDispute(agreementId, 0);
        _assertState(agreementId, ZineshEscrowV3_2.State.Released);
        require(address(escrow).balance == 0, "escrow drained");
    }

    function test19BackupResolverHasSecondSevenDays() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        _fund(agreementId);
        vm.prank(BUYER);
        escrow.openDispute(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.warp(agreement.disputeOpenedAt + 7 days + 1);
        vm.expectRevert(ZineshEscrowV3_2.Unauthorized.selector);
        vm.prank(RESOLVER);
        escrow.resolveDispute(agreementId, 1);
        vm.prank(BACKUP_RESOLVER);
        escrow.resolveDispute(agreementId, 1);
        _assertState(agreementId, ZineshEscrowV3_2.State.Refunded);
        require(address(escrow).balance == 0, "escrow drained");
    }

    function test20BuyerRecoversAfterBothResolversTimeout() public {
        uint256 agreementId = _create(uint64(3 days));
        _accept(agreementId);
        _fund(agreementId);
        vm.prank(BUYER);
        escrow.openDispute(agreementId);
        ZineshEscrowV3_2.Agreement memory agreement = escrow.getAgreement(agreementId);
        vm.expectRevert(ZineshEscrowV3_2.DeadlineNotReached.selector);
        vm.prank(BUYER);
        escrow.claimDisputeTimeout(agreementId);
        vm.warp(agreement.disputeOpenedAt + 14 days + 1);
        vm.expectRevert(ZineshEscrowV3_2.DeadlinePassed.selector);
        vm.prank(BACKUP_RESOLVER);
        escrow.resolveDispute(agreementId, 0);
        vm.prank(BUYER);
        escrow.claimDisputeTimeout(agreementId);
        _assertState(agreementId, ZineshEscrowV3_2.State.Refunded);
        require(address(escrow).balance == 0, "escrow drained");
    }
}
