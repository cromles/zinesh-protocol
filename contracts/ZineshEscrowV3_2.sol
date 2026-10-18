// SPDX-License-Identifier: UNLICENSED
pragma solidity 0.8.30;

contract ZineshEscrowV3_2 {
    enum State { Created, Accepted, Funded, EvidenceSubmitted, Disputed, Released, Refunded, Cancelled, Expired }

    struct Agreement {
        address buyer;
        address seller;
        address resolver;
        address backupResolver;
        uint128 amount;
        uint64 createdAt;
        uint64 acceptanceDeadline;
        uint64 acceptedAt;
        uint64 fundingDeadline;
        uint64 fundedAt;
        uint64 workDuration;
        uint64 workDeadline;
        uint64 evidenceSubmittedAt;
        uint64 disputeOpenedAt;
        State state;
        bool buyerAccepted;
        bool sellerAccepted;
        address refundRequester;
        bytes32 termsHash;
        bytes32 planHash;
        bytes32 evidenceHash;
    }

    error InvalidAddress();
    error InvalidAmount();
    error InvalidHash();
    error InvalidDuration();
    error InvalidState();
    error Unauthorized();
    error WrongValue();
    error AlreadyAccepted();
    error InvalidDecision();
    error DeadlineNotReached();
    error DeadlinePassed();
    error TransferFailed();
    error Reentrancy();

    event AgreementCreated(uint256 indexed agreementId, address indexed buyer, address indexed seller, address resolver, address backupResolver, uint256 amount, uint64 workDuration);
    event PlanAccepted(uint256 indexed agreementId, address indexed actor);
    event Funded(uint256 indexed agreementId, uint256 amount, uint64 workDeadline);
    event EvidenceSubmitted(uint256 indexed agreementId, bytes32 evidenceHash, uint64 reviewDeadline);
    event RefundRequested(uint256 indexed agreementId, address indexed requester);
    event DisputeOpened(uint256 indexed agreementId, address indexed actor, uint64 primaryDeadline, uint64 fallbackDeadline);
    event Resolved(uint256 indexed agreementId, State finalState, address recipient, uint256 amount);
    event Cancelled(uint256 indexed agreementId);
    event Expired(uint256 indexed agreementId);

    uint64 public constant ACCEPTANCE_WINDOW = 1 days;
    uint64 public constant FUNDING_WINDOW = 1 days;
    uint64 public constant EVIDENCE_GRACE = 1 days;
    uint64 public constant REVIEW_WINDOW = 2 days;
    uint64 public constant PRIMARY_DISPUTE_WINDOW = 7 days;
    uint64 public constant BACKUP_DISPUTE_WINDOW = 7 days;
    uint64 public constant MIN_WORK_DURATION = 1 days;
    uint64 public constant MAX_WORK_DURATION = 365 days;

    uint256 public agreementCount;
    mapping(uint256 => Agreement) private agreements;
    uint256 private locked = 1;

    modifier nonReentrant() {
        if (locked != 1) revert Reentrancy();
        locked = 2;
        _;
        locked = 1;
    }

    function createAgreement(
        address seller,
        address resolver,
        address backupResolver,
        uint128 amount,
        bytes32 termsHash,
        bytes32 planHash,
        uint64 workDuration
    ) external returns (uint256 agreementId) {
        if (seller == address(0) || resolver == address(0) || backupResolver == address(0)) revert InvalidAddress();
        if (
            seller == msg.sender || resolver == msg.sender || backupResolver == msg.sender ||
            seller == resolver || seller == backupResolver || resolver == backupResolver
        ) revert InvalidAddress();
        if (amount == 0) revert InvalidAmount();
        if (termsHash == bytes32(0) || planHash == bytes32(0)) revert InvalidHash();
        if (workDuration < MIN_WORK_DURATION || workDuration > MAX_WORK_DURATION) revert InvalidDuration();

        uint64 createdAt = uint64(block.timestamp);
        agreementId = ++agreementCount;
        agreements[agreementId] = Agreement({
            buyer: msg.sender,
            seller: seller,
            resolver: resolver,
            backupResolver: backupResolver,
            amount: amount,
            createdAt: createdAt,
            acceptanceDeadline: createdAt + ACCEPTANCE_WINDOW,
            acceptedAt: 0,
            fundingDeadline: 0,
            fundedAt: 0,
            workDuration: workDuration,
            workDeadline: 0,
            evidenceSubmittedAt: 0,
            disputeOpenedAt: 0,
            state: State.Created,
            buyerAccepted: false,
            sellerAccepted: false,
            refundRequester: address(0),
            termsHash: termsHash,
            planHash: planHash,
            evidenceHash: bytes32(0)
        });
        emit AgreementCreated(agreementId, msg.sender, seller, resolver, backupResolver, amount, workDuration);
    }

    function acceptPlan(uint256 agreementId) external {
        Agreement storage agreement = agreements[agreementId];
        if (agreement.state != State.Created) revert InvalidState();
        if (block.timestamp > agreement.acceptanceDeadline) revert DeadlinePassed();
        if (msg.sender == agreement.buyer) {
            if (agreement.buyerAccepted) revert AlreadyAccepted();
            agreement.buyerAccepted = true;
        } else if (msg.sender == agreement.seller) {
            if (agreement.sellerAccepted) revert AlreadyAccepted();
            agreement.sellerAccepted = true;
        } else revert Unauthorized();

        if (agreement.buyerAccepted && agreement.sellerAccepted) {
            agreement.state = State.Accepted;
            agreement.acceptedAt = uint64(block.timestamp);
            agreement.fundingDeadline = uint64(block.timestamp) + FUNDING_WINDOW;
        }
        emit PlanAccepted(agreementId, msg.sender);
    }

    function expireUnaccepted(uint256 agreementId) external {
        Agreement storage agreement = agreements[agreementId];
        if (agreement.state != State.Created) revert InvalidState();
        if (block.timestamp <= agreement.acceptanceDeadline) revert DeadlineNotReached();
        agreement.state = State.Expired;
        emit Expired(agreementId);
    }

    function fund(uint256 agreementId) external payable {
        Agreement storage agreement = agreements[agreementId];
        if (msg.sender != agreement.buyer) revert Unauthorized();
        if (agreement.state != State.Accepted) revert InvalidState();
        if (block.timestamp > agreement.fundingDeadline) revert DeadlinePassed();
        if (msg.value != agreement.amount) revert WrongValue();
        agreement.fundedAt = uint64(block.timestamp);
        agreement.workDeadline = uint64(block.timestamp) + agreement.workDuration;
        agreement.state = State.Funded;
        emit Funded(agreementId, msg.value, agreement.workDeadline);
    }

    function expireUnfunded(uint256 agreementId) external {
        Agreement storage agreement = agreements[agreementId];
        if (agreement.state != State.Accepted) revert InvalidState();
        if (block.timestamp <= agreement.fundingDeadline) revert DeadlineNotReached();
        agreement.state = State.Expired;
        emit Expired(agreementId);
    }

    function submitEvidence(uint256 agreementId, bytes32 evidenceHash) external {
        Agreement storage agreement = agreements[agreementId];
        if (msg.sender != agreement.seller) revert Unauthorized();
        if (agreement.state != State.Funded) revert InvalidState();
        if (block.timestamp > agreement.workDeadline + EVIDENCE_GRACE) revert DeadlinePassed();
        if (evidenceHash == bytes32(0)) revert InvalidHash();
        agreement.evidenceHash = evidenceHash;
        agreement.evidenceSubmittedAt = uint64(block.timestamp);
        agreement.state = State.EvidenceSubmitted;
        emit EvidenceSubmitted(agreementId, evidenceHash, uint64(block.timestamp) + REVIEW_WINDOW);
    }

    function release(uint256 agreementId) external nonReentrant {
        Agreement storage agreement = agreements[agreementId];
        if (msg.sender != agreement.buyer) revert Unauthorized();
        if (agreement.state != State.EvidenceSubmitted || agreement.evidenceHash == bytes32(0)) revert InvalidState();
        _settle(agreementId, agreement, State.Released, agreement.seller);
    }

    function claimEvidenceReviewTimeout(uint256 agreementId) external nonReentrant {
        Agreement storage agreement = agreements[agreementId];
        if (msg.sender != agreement.seller) revert Unauthorized();
        if (agreement.state != State.EvidenceSubmitted) revert InvalidState();
        if (block.timestamp <= agreement.evidenceSubmittedAt + REVIEW_WINDOW) revert DeadlineNotReached();
        _settle(agreementId, agreement, State.Released, agreement.seller);
    }

    function claimNoEvidenceTimeout(uint256 agreementId) external nonReentrant {
        Agreement storage agreement = agreements[agreementId];
        if (msg.sender != agreement.buyer) revert Unauthorized();
        if (agreement.state != State.Funded || agreement.evidenceHash != bytes32(0)) revert InvalidState();
        if (block.timestamp <= agreement.workDeadline + EVIDENCE_GRACE) revert DeadlineNotReached();
        _settle(agreementId, agreement, State.Refunded, agreement.buyer);
    }

    function requestRefund(uint256 agreementId) external {
        Agreement storage agreement = agreements[agreementId];
        if (agreement.state != State.Funded && agreement.state != State.EvidenceSubmitted && agreement.state != State.Disputed) revert InvalidState();
        if (msg.sender != agreement.buyer && msg.sender != agreement.seller) revert Unauthorized();
        agreement.refundRequester = msg.sender;
        emit RefundRequested(agreementId, msg.sender);
    }

    function approveRefund(uint256 agreementId) external nonReentrant {
        Agreement storage agreement = agreements[agreementId];
        if (agreement.state != State.Funded && agreement.state != State.EvidenceSubmitted && agreement.state != State.Disputed) revert InvalidState();
        address requester = agreement.refundRequester;
        if (requester == address(0) || requester == msg.sender) revert Unauthorized();
        if (msg.sender != agreement.buyer && msg.sender != agreement.seller) revert Unauthorized();
        _settle(agreementId, agreement, State.Refunded, agreement.buyer);
    }

    function openDispute(uint256 agreementId) external {
        Agreement storage agreement = agreements[agreementId];
        if (agreement.state != State.Funded && agreement.state != State.EvidenceSubmitted) revert InvalidState();
        if (msg.sender != agreement.buyer && msg.sender != agreement.seller) revert Unauthorized();
        agreement.refundRequester = address(0);
        agreement.disputeOpenedAt = uint64(block.timestamp);
        agreement.state = State.Disputed;
        emit DisputeOpened(
            agreementId,
            msg.sender,
            uint64(block.timestamp) + PRIMARY_DISPUTE_WINDOW,
            uint64(block.timestamp) + PRIMARY_DISPUTE_WINDOW + BACKUP_DISPUTE_WINDOW
        );
    }

    function resolveDispute(uint256 agreementId, uint8 decision) external nonReentrant {
        Agreement storage agreement = agreements[agreementId];
        if (agreement.state != State.Disputed) revert InvalidState();
        uint256 primaryDeadline = agreement.disputeOpenedAt + PRIMARY_DISPUTE_WINDOW;
        uint256 fallbackDeadline = primaryDeadline + BACKUP_DISPUTE_WINDOW;
        if (block.timestamp <= primaryDeadline) {
            if (msg.sender != agreement.resolver) revert Unauthorized();
        } else {
            if (block.timestamp > fallbackDeadline) revert DeadlinePassed();
            if (msg.sender != agreement.backupResolver) revert Unauthorized();
        }
        if (decision == 0) _settle(agreementId, agreement, State.Released, agreement.seller);
        else if (decision == 1) _settle(agreementId, agreement, State.Refunded, agreement.buyer);
        else revert InvalidDecision();
    }

    function claimDisputeTimeout(uint256 agreementId) external nonReentrant {
        Agreement storage agreement = agreements[agreementId];
        if (msg.sender != agreement.buyer) revert Unauthorized();
        if (agreement.state != State.Disputed) revert InvalidState();
        if (block.timestamp <= agreement.disputeOpenedAt + PRIMARY_DISPUTE_WINDOW + BACKUP_DISPUTE_WINDOW) revert DeadlineNotReached();
        _settle(agreementId, agreement, State.Refunded, agreement.buyer);
    }

    function cancelAgreement(uint256 agreementId) external {
        Agreement storage agreement = agreements[agreementId];
        if (msg.sender != agreement.buyer) revert Unauthorized();
        if (agreement.state != State.Created) revert InvalidState();
        agreement.state = State.Cancelled;
        emit Cancelled(agreementId);
    }

    function getAgreement(uint256 agreementId) external view returns (Agreement memory) {
        return agreements[agreementId];
    }

    function _settle(uint256 agreementId, Agreement storage agreement, State finalState, address recipient) private {
        uint256 amount = agreement.amount;
        agreement.state = finalState;
        (bool ok,) = payable(recipient).call{value: amount}("");
        if (!ok) revert TransferFailed();
        emit Resolved(agreementId, finalState, recipient, amount);
    }

    receive() external payable { revert WrongValue(); }
}
