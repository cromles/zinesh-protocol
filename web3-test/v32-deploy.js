import {BYTECODE} from './v32-bytecode.js';

const CHAIN_ID='0x14a34';
const $=id=>document.getElementById(id);
const ui={status:$('status'),account:$('account'),consent:$('consent'),deploy:$('deploy'),tx:$('tx'),contract:$('contract')};
let selected='';
let busy=false;

const short=value=>`${value.slice(0,10)}…${value.slice(-8)}`;
function setStatus(message,type='waiting'){ui.status.className=`status ${type}`;ui.status.querySelector('span').textContent=message;}

async function network(){
  try{await ethereum.request({method:'wallet_switchEthereumChain',params:[{chainId:CHAIN_ID}]});}
  catch(error){
    if(error?.code!==4902)throw error;
    await ethereum.request({method:'wallet_addEthereumChain',params:[{chainId:CHAIN_ID,chainName:'Base Sepolia',nativeCurrency:{name:'Ether',symbol:'ETH',decimals:18},rpcUrls:['https://sepolia.base.org'],blockExplorerUrls:['https://sepolia.basescan.org']}]});
  }
}

async function connect(){
  if(!window.ethereum)throw new Error('MetaMask bulunamadı. Sayfayı MetaMask tarayıcısında aç.');
  await network();
  const accounts=await ethereum.request({method:'eth_requestAccounts'});
  selected=accounts[0];
  ui.account.textContent=short(selected);
  ui.deploy.textContent='ZineshEscrowV3.2’yi dağıt →';
  ui.deploy.disabled=!ui.consent.checked;
  setStatus('Cüzdan bağlandı. Onay kutusunu işaretleyerek dağıtıma hazırlan.','ready');
}

async function receipt(hash){
  for(let attempt=0;attempt<100;attempt++){
    const value=await ethereum.request({method:'eth_getTransactionReceipt',params:[hash]});
    if(value)return value;
    await new Promise(resolve=>setTimeout(resolve,1500));
  }
  throw new Error('İşlem gönderildi ancak onay bekleme süresi doldu. Basescan’den kontrol et.');
}

async function deploy(){
  if(!selected){await connect();return;}
  if(!ui.consent.checked)throw new Error('Test sözleşmesi onay kutusunu işaretle.');
  const tx={from:selected,data:BYTECODE};
  const estimate=await ethereum.request({method:'eth_estimateGas',params:[tx]});
  const gas=`0x${(BigInt(estimate)*12n/10n).toString(16)}`;
  const hash=await ethereum.request({method:'eth_sendTransaction',params:[{...tx,gas}]});
  ui.tx.href=`https://sepolia.basescan.org/tx/${hash}`;
  ui.tx.classList.remove('hidden');
  setStatus('Dağıtım gönderildi; Base Sepolia onayı bekleniyor…','waiting');
  const result=await receipt(hash);
  if(BigInt(result.status)!==1n||!result.contractAddress)throw new Error('Sözleşme dağıtımı zincirde başarısız oldu.');
  const code=await ethereum.request({method:'eth_getCode',params:[result.contractAddress,'latest']});
  if(code==='0x')throw new Error('Dağıtım adresinde sözleşme kodu bulunamadı.');
  localStorage.setItem('zineshV32Contract',result.contractAddress);
  ui.contract.href=`https://sepolia.basescan.org/address/${result.contractAddress}`;
  ui.contract.textContent=`V3.2 sözleşmesini aç: ${short(result.contractAddress)} ↗`;
  ui.contract.classList.remove('hidden');
  ui.deploy.textContent='Dağıtım tamamlandı ✓';
  ui.deploy.disabled=true;
  setStatus(`V3.2 başarıyla dağıtıldı: ${result.contractAddress}`,'ready');
}

async function run(action){
  if(busy)return;
  busy=true;ui.deploy.disabled=true;
  try{await action();}
  catch(error){setStatus(error?.message||'İşlem tamamlanamadı.','error');}
  finally{busy=false;if(ui.contract.classList.contains('hidden'))ui.deploy.disabled=selected?!ui.consent.checked:false;}
}

ui.consent.addEventListener('change',()=>{if(selected&&!busy)ui.deploy.disabled=!ui.consent.checked;});
ui.deploy.addEventListener('click',()=>run(selected?deploy:connect));
if(window.ethereum){
  ethereum.on?.('accountsChanged',accounts=>{selected=accounts[0]||'';ui.account.textContent=selected?short(selected):'—';ui.deploy.textContent=selected?'ZineshEscrowV3.2’yi dağıt →':'MetaMask’ı bağla →';ui.deploy.disabled=selected?!ui.consent.checked:false;});
  ethereum.on?.('chainChanged',()=>location.reload());
}
