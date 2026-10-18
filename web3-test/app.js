const CONTRACT='0x2cc166931116226e9b110e044953361f8c0a775e';
const AGREEMENT_ID=1n;
const CHAIN_ID='0x14a34';
const SELECTORS={accept:'e93ab2a1',fund:'ca1d209d',evidence:'912da4db',release:'37bdc99b',get:'4f9f6fe6'};
const STATE_NAMES=['Oluşturuldu','Kabul edildi','Emanette','Kanıt kaydedildi','İtirazda','Serbest bırakıldı','İade edildi','İptal edildi'];
const $=id=>document.getElementById(id);
const ui={status:$('status'),account:$('account'),role:$('role'),state:$('chain-state'),amount:$('amount'),primary:$('primary'),refresh:$('refresh'),evidence:$('evidence'),evidenceWrap:$('evidence-wrap'),lastTx:$('last-tx')};
let agreement=null;
let busy=false;

const short=value=>`${value.slice(0,10)}…${value.slice(-8)}`;
const same=(a,b)=>a?.toLowerCase()===b?.toLowerCase();
const word=value=>BigInt(value).toString(16).padStart(64,'0');
const data=(selector,...words)=>`0x${selector}${words.join('')}`;

function setStatus(message,type='waiting'){
  ui.status.className=`status ${type}`;
  ui.status.querySelector('span').textContent=message;
}

async function network(){
  try{await ethereum.request({method:'wallet_switchEthereumChain',params:[{chainId:CHAIN_ID}]});}
  catch(error){
    if(error?.code!==4902)throw error;
    await ethereum.request({method:'wallet_addEthereumChain',params:[{chainId:CHAIN_ID,chainName:'Base Sepolia',nativeCurrency:{name:'Ether',symbol:'ETH',decimals:18},rpcUrls:['https://sepolia.base.org'],blockExplorerUrls:['https://sepolia.basescan.org']}]});
  }
}

function expectedRole(){
  if(!agreement)return null;
  if(agreement.state===0&&!agreement.buyerAccepted)return {name:'Alıcı',address:agreement.buyer};
  if(agreement.state===0&&!agreement.sellerAccepted)return {name:'Satıcı',address:agreement.seller};
  if(agreement.state===1)return {name:'Alıcı',address:agreement.buyer};
  if(agreement.state===2)return {name:'Satıcı',address:agreement.seller};
  if(agreement.state===3)return {name:'Alıcı',address:agreement.buyer};
  return null;
}

async function account(preferred=''){
  await network();
  let accounts=await ethereum.request({method:'eth_requestAccounts'});
  if(preferred&&!accounts.some(value=>same(value,preferred))){
    setStatus(`${short(preferred)} hesabını MetaMask’ta seç.`, 'waiting');
    await ethereum.request({method:'wallet_requestPermissions',params:[{eth_accounts:{}}]});
    accounts=await ethereum.request({method:'eth_requestAccounts'});
  }
  return (preferred&&accounts.find(value=>same(value,preferred)))||accounts[0];
}

function decode(raw){
  const words=raw.slice(2).match(/.{64}/g)||[];
  if(words.length<13)throw new Error('Anlaşma zincirden okunamadı.');
  return {
    buyer:`0x${words[0].slice(24)}`,
    seller:`0x${words[1].slice(24)}`,
    resolver:`0x${words[2].slice(24)}`,
    amount:BigInt(`0x${words[3]}`),
    state:Number(BigInt(`0x${words[6]}`)),
    buyerAccepted:BigInt(`0x${words[7]}`)!==0n,
    sellerAccepted:BigInt(`0x${words[8]}`)!==0n,
  };
}

function phase(){
  if(!agreement)return -1;
  if(agreement.state===0)return agreement.buyerAccepted||agreement.sellerAccepted?1:0;
  if(agreement.state===1)return 2;
  if(agreement.state===2)return 3;
  if(agreement.state===3)return 4;
  return 5;
}

function render(selected=''){
  const role=same(selected,agreement.buyer)?'Alıcı':same(selected,agreement.seller)?'Satıcı':same(selected,agreement.resolver)?'Çözümleyici':'Bu anlaşmada taraf değil';
  ui.account.textContent=short(selected);
  ui.role.textContent=role;
  ui.state.textContent=STATE_NAMES[agreement.state]||`Durum ${agreement.state}`;
  ui.amount.textContent=`${Number(agreement.amount)/1e18} test ETH`;
  ui.evidenceWrap.classList.toggle('hidden',agreement.state!==2);
  document.querySelectorAll('[data-step]').forEach(item=>{
    const n=Number(item.dataset.step),p=phase();
    item.classList.toggle('done',n<p);
    item.classList.toggle('active',n===p);
  });
  const expected=expectedRole();
  if(!expected){
    ui.primary.textContent=agreement.state===5?'Akış tamamlandı ✓':agreement.state===6?'Tutar iade edildi ✓':'Anlaşma kapandı';
    ui.primary.disabled=true;
  }else if(agreement.state===0){
    ui.primary.textContent=`${expected.name} olarak planı kabul et →`;
  }else if(agreement.state===1){
    ui.primary.textContent='Alıcı olarak 0.001 test ETH kilitle →';
  }else if(agreement.state===2){
    ui.primary.textContent='Satıcı olarak teslim kanıtını kaydet →';
  }else if(agreement.state===3){
    ui.primary.textContent='Alıcı olarak ödemeyi serbest bırak →';
  }
  if(expected&&!same(selected,expected.address))setStatus(`Sıradaki adım ${expected.name} hesabını bekliyor: ${short(expected.address)}`,'waiting');
  else setStatus('Anlaşma #1 zincirden okundu. Sıradaki adım hazır.','ready');
}

async function load(){
  if(!window.ethereum)throw new Error('MetaMask bulunamadı. Sayfayı MetaMask tarayıcısında aç.');
  const selected=await account();
  const raw=await ethereum.request({method:'eth_call',params:[{to:CONTRACT,data:data(SELECTORS.get,word(AGREEMENT_ID))},'latest']});
  agreement=decode(raw);
  render(selected);
}

async function hash(text){
  const bytes=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(text));
  return Array.from(new Uint8Array(bytes),byte=>byte.toString(16).padStart(2,'0')).join('');
}

async function receipt(txHash){
  for(let attempt=0;attempt<80;attempt++){
    const value=await ethereum.request({method:'eth_getTransactionReceipt',params:[txHash]});
    if(value)return value;
    await new Promise(resolve=>setTimeout(resolve,1500));
  }
  throw new Error('İşlem gönderildi; onay süresi doldu. Basescan’den kontrol et.');
}

async function send(from,txData,value){
  const tx={from,to:CONTRACT,data:txData,...(value?{value}:{})};
  const estimate=await ethereum.request({method:'eth_estimateGas',params:[tx]});
  const gas=`0x${(BigInt(estimate)*12n/10n).toString(16)}`;
  const txHash=await ethereum.request({method:'eth_sendTransaction',params:[{...tx,gas}]});
  setStatus('İşlem gönderildi; Base Sepolia onayı bekleniyor…','waiting');
  const result=await receipt(txHash);
  if(BigInt(result.status)!==1n)throw new Error('İşlem zincirde başarısız oldu.');
  ui.lastTx.href=`https://sepolia.basescan.org/tx/${txHash}`;
  ui.lastTx.classList.remove('hidden');
}

async function act(){
  if(!agreement){await load();return;}
  const expected=expectedRole();
  if(!expected)return;
  const selected=await account(expected.address);
  if(!same(selected,expected.address))throw new Error(`${expected.name} hesabını seç: ${expected.address}`);
  if(agreement.state===0)await send(selected,data(SELECTORS.accept,word(AGREEMENT_ID)));
  else if(agreement.state===1)await send(selected,data(SELECTORS.fund,word(AGREEMENT_ID)),`0x${agreement.amount.toString(16)}`);
  else if(agreement.state===2){
    const evidence=ui.evidence.value.trim();
    if(!evidence)throw new Error('Teslim kanıtı özeti boş bırakılamaz.');
    await send(selected,data(SELECTORS.evidence,word(AGREEMENT_ID),await hash(evidence)));
  }else if(agreement.state===3)await send(selected,data(SELECTORS.release,word(AGREEMENT_ID)));
  await load();
}

async function run(action){
  if(busy)return;
  busy=true;ui.primary.disabled=true;ui.refresh.disabled=true;
  try{await action();}
  catch(error){setStatus(error?.message||'İşlem tamamlanamadı.','error');}
  finally{busy=false;ui.refresh.disabled=false;if(agreement&&expectedRole())ui.primary.disabled=false;else if(!agreement)ui.primary.disabled=false;}
}

ui.primary.addEventListener('click',()=>run(act));
ui.refresh.addEventListener('click',()=>run(load));
if(window.ethereum){
  ethereum.on?.('accountsChanged',()=>run(load));
  ethereum.on?.('chainChanged',()=>run(load));
  run(load);
}
