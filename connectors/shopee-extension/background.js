const DEFAULT_HUB='https://pan.itoom.work';
const VERSION='2.4.17';
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const txt=v=>v==null?'':String(v);
const num=v=>{const n=Number(v);if(!Number.isFinite(n))return 0;return Math.abs(n)>=100000?n/100000:n};
const OPTIONAL_NON_PURCHASE_STATUS_TYPES=new Set([9,12]);
function canSkipOptionalStatusApiError(listType,code){return OPTIONAL_NON_PURCHASE_STATUS_TYPES.has(Number(listType))&&Number(code)===33800002;}
function appendSkippedStatusTypes(st,listType){const rows=Array.isArray(st?.skippedStatusTypes)?st.skippedStatusTypes:[];return [...new Set([...rows.map(Number).filter(Number.isFinite),Number(listType)])];}

async function execMain(tabId,func,args=[]){
  const rows=await chrome.scripting.executeScript({target:{tabId},world:'MAIN',func,args});
  return rows?.[0]?.result;
}
async function mainWorldAccount(tabId){return execMain(tabId,async()=>{const url=`${location.origin}/api/v4/account/basic/get_account_info`;const res=await fetch(url,{credentials:'include',headers:{accept:'application/json'}});let json=null;try{json=await res.json()}catch{}const d=json?.data||{};return {http:res.status,ok:res.ok,url,json_error:json?.error??0,account:{userid:d.userid??d.user_id??null,username:d.username??'',nickname:d.nickname??'',country:d.country??''}};});}
async function mainWorldPage(tabId,offset,limit){return execMain(tabId,async(offset,limit)=>{const url=`${location.origin}/api/v4/order/get_all_order_and_checkout_list?limit=${limit}&offset=${offset}`;const res=await fetch(url,{method:'GET',credentials:'include',cache:'no-store',headers:{accept:'application/json, text/plain, */*','x-api-source':'pc'}});let json=null;try{json=await res.json()}catch{}return {http:res.status,ok:res.ok,url,json};},[offset,limit]);}
async function mainWorldStatusPage(tabId,listType,offset,limit){return execMain(tabId,async(listType,offset,limit)=>{const url=`${location.origin}/api/v4/order/get_order_list?list_type=${encodeURIComponent(listType)}&offset=${offset}&limit=${limit}`;const res=await fetch(url,{method:'GET',credentials:'include',cache:'no-store',headers:{accept:'application/json, text/plain, */*','x-api-source':'pc'}});let json=null;try{json=await res.json()}catch{}return {http:res.status,ok:res.ok,url,json};},[listType,offset,limit]);}
async function mainWorldDetail(tabId,orderId){return execMain(tabId,async orderId=>{const url=`${location.origin}/api/v4/order/get_order_detail?order_id=${encodeURIComponent(orderId)}`;const res=await fetch(url,{credentials:'include',headers:{accept:'application/json','x-api-source':'pc'}});let json=null;try{json=await res.json()}catch{}return {http:res.status,ok:res.ok,url,json};},[orderId]);}
async function mainWorldProduct(tabId,shopId,itemId){return execMain(tabId,async(shopId,itemId)=>{const urls=[`${location.origin}/api/v4/pdp/get_pc?shop_id=${encodeURIComponent(shopId)}&item_id=${encodeURIComponent(itemId)}&tz_offset_minutes=420&detail_level=0`,`${location.origin}/api/v4/item/get?shopid=${encodeURIComponent(shopId)}&itemid=${encodeURIComponent(itemId)}`];for(const url of urls){try{const res=await fetch(url,{credentials:'include',cache:'no-store',headers:{accept:'application/json, text/plain, */*','x-api-source':'pc'}});let json=null;try{json=await res.json()}catch{}if(res.ok&&json&&Number(json.error||0)===0)return {http:res.status,ok:true,url,json};}catch{}}return {http:0,ok:false,url:urls[0],json:null};},[shopId,itemId]);}

function orderRecordFromEntry(entry){
  if(!entry||typeof entry!=='object'||Array.isArray(entry))return null;
  const wrapped=entry?.order_list_detail||entry?.order_detail||null;
  const record=wrapped&&typeof wrapped==='object'?wrapped:entry;
  const looksLikeOrder=record?.info_card&&typeof record.info_card==='object'||record?.order_id!==undefined||record?.order_sn!==undefined;
  if(!looksLikeOrder)return null;
  if(wrapped&&record.list_type===undefined&&entry.list_type!==undefined)return {...record,list_type:entry.list_type};
  return record;
}
function parseOrderArray(format,c){
  if(!Array.isArray(c))return null;
  if(c.length===0)return {recognized:true,format,records:[],rawCount:0};
  const records=c.map(orderRecordFromEntry);
  if(records.some(x=>!x))return {recognized:false,format,records:[],rawCount:c.length};
  return {recognized:true,format,records,rawCount:c.length};
}
function findNamedOrderArray(j,maxDepth=6){
  const names=new Set(['details_list','order_list','order_or_checkout_data','order_list_details']);
  const seen=new Set();let empty=null;
  function walk(v,path,depth){
    if(depth>maxDepth||v==null||typeof v!=='object'||seen.has(v))return null;seen.add(v);
    for(const [k,val] of Object.entries(v)){
      const p=path?path+'.'+k:k;
      if(names.has(k)&&Array.isArray(val)){
        const parsed=parseOrderArray(p,val);
        if(parsed?.recognized&&parsed.rawCount>0)return parsed;
        if(parsed?.recognized&&!empty)empty=parsed;
      }
      if(val&&typeof val==='object'&&!Array.isArray(val)){const found=walk(val,p,depth+1);if(found)return found;}
    }
    return null;
  }
  return walk(j,'',0)||empty;
}
function pickKnownOrderArray(j,candidates){
  let firstMalformed=null;
  for(const [format,c] of candidates){
    const parsed=parseOrderArray(format,c);if(!parsed)continue;
    if(parsed.recognized)return parsed;
    if(!firstMalformed)firstMalformed=parsed;
  }
  const discovered=findNamedOrderArray(j);
  if(discovered)return discovered;
  return firstMalformed||{recognized:false,format:'unknown',records:[],rawCount:0};
}
function pickDetailsInfo(j){return pickKnownOrderArray(j,[['new_data.order_or_checkout_data',j?.new_data?.order_or_checkout_data],['data.new_data.order_or_checkout_data',j?.data?.new_data?.order_or_checkout_data],['data.order_data.details_list',j?.data?.order_data?.details_list],['data.details_list',j?.data?.details_list],['data.order_list',j?.data?.order_list],['order_data.details_list',j?.order_data?.details_list],['details_list',j?.details_list]]);}
function pickDetails(j){return pickDetailsInfo(j).records;}
function pickStatusDetailsInfo(j){return pickKnownOrderArray(j,[['data.details_list',j?.data?.details_list],['data.order_data.details_list',j?.data?.order_data?.details_list],['new_data.details_list',j?.new_data?.details_list],['details_list',j?.details_list],['data.order_list',j?.data?.order_list],['new_data.order_or_checkout_data',j?.new_data?.order_or_checkout_data],['data.new_data.order_or_checkout_data',j?.data?.new_data?.order_or_checkout_data]]);}
function arrayShapePaths(j,maxDepth=5){const out=[],seen=new Set();function walk(v,path,depth){if(depth>maxDepth||v==null||typeof v!=='object'||seen.has(v)||out.length>=24)return;seen.add(v);for(const [k,val] of Object.entries(v)){const p=path?path+'.'+k:k;if(Array.isArray(val)){out.push(`${p}[${val.length}]`);continue;}if(val&&typeof val==='object')walk(val,p,depth+1);}}walk(j,'',0);return out;}
function compactShapeValue(v){if(v==null||['string','number','boolean'].includes(typeof v))return v;if(Array.isArray(v))return `array:${v.length}`;if(typeof v==='object')return {type:'object',keys:Object.keys(v).slice(0,12)};return typeof v;}
function responseShape(j){return {error:j?.error??null,error_msg:j?.error_msg??j?.message??'',keys:Object.keys(j||{}).slice(0,30),new_data_keys:Object.keys(j?.new_data||{}).slice(0,30),data_keys:Object.keys(j?.data||{}).slice(0,30),new_data_next_offset:j?.new_data?.next_offset??null,data_next_offset:j?.data?.next_offset??null,new_data_translation_status:compactShapeValue(j?.new_data?.translation_status),data_translation_status:compactShapeValue(j?.data?.translation_status),translation_status:compactShapeValue(j?.new_data?.translation_status??j?.data?.translation_status),arrays:arrayShapePaths(j)};}
function extractCategoryInfo(json){
  const root=json?.data||json||{};const seen=new Set();let best=[];
  function rowToCat(x){if(!x||typeof x!=='object')return null;const id=x.catid??x.category_id??x.id??'';const name=objectText(x.name??x.display_name??x.category_name??x.label??'');if(!name&&!id)return null;return {id:txt(id),name};}
  function walk(v,depth){if(depth>7||v==null||typeof v!=='object'||seen.has(v))return;seen.add(v);for(const [k,val] of Object.entries(v)){if(Array.isArray(val)&&/(categor|breadcrumb)/i.test(k)){const cats=val.map(rowToCat).filter(Boolean);if(cats.length>best.length)best=cats;}if(val&&typeof val==='object')walk(val,depth+1);}}
  walk(root,0);if(!best.length){const id=deepFind(root,['catid','category_id']);const name=deepTextWhere(root,['category_name','category_display_name'],p=>/categor/i.test(p));if(id||name)best=[{id:txt(id?.value||''),name:cleanText(name?.value||'')}];}
  if(!best.length)return null;const names=best.map(x=>x.name).filter(Boolean);return {category_id:txt(best.at(-1)?.id||''),category_name:names.at(-1)||names[0]||'',category_path:names.join(' > ')};
}
function metadataOnlyInfo(j,paths=['new_data']){if(arrayShapePaths(j).length!==0)return null;for(const path of paths){const box=j?.[path];if(!box||typeof box!=='object'||Array.isArray(box))continue;if(!Object.prototype.hasOwnProperty.call(box,'next_offset')||!Object.prototype.hasOwnProperty.call(box,'translation_status'))continue;if(!Number.isFinite(Number(box.next_offset)))continue;return {path,next_offset:Number(box.next_offset),translation_status:compactShapeValue(box.translation_status)};}return null;}
function primaryMetadataOnly(j){return Boolean(metadataOnlyInfo(j,['new_data']));}
function statusMetadataOnlyInfo(j){return metadataOnlyInfo(j,['data','new_data']);}
function deepNextOffset(j,maxDepth=6){const seen=new Set();function walk(v,depth){if(depth>maxDepth||v==null||typeof v!=='object'||seen.has(v))return null;seen.add(v);for(const [k,val] of Object.entries(v)){if(k==='next_offset'&&val!==undefined&&val!==null&&val!==''&&Number.isFinite(Number(val)))return Number(val);if(val&&typeof val==='object'&&!Array.isArray(val)){const found=walk(val,depth+1);if(found!==null)return found;}}return null;}return walk(j,0);}
function nextOffset(j,offset,limit,count){for(const v of [j?.new_data?.next_offset,j?.data?.new_data?.next_offset,j?.data?.order_data?.next_offset,j?.data?.next_offset,j?.next_offset])if(v!==undefined&&v!==null&&v!==''&&Number.isFinite(Number(v)))return Number(v);const nested=deepNextOffset(j);if(nested!==null)return nested;return count<limit?-1:offset+limit;}
function imageUrl(v){v=txt(v);if(!v)return '';if(/^https?:/.test(v))return v;return `https://down-th.img.susercontent.com/file/${v}`;}
function productUrl(shopId,itemId){return shopId&&itemId?`https://shopee.co.th/product/${shopId}/${itemId}`:'';}
function statusFromListType(v){return ({3:'completed',4:'cancelled',7:'shipping',8:'delivering',9:'unpaid',12:'refund'})[Number(v)]||txt(v);}
function normalizeEpochSeconds(value){if(value==null||value==='')return null;if(typeof value==='string'&&!/^\d+(?:\.\d+)?$/.test(value.trim())){const s=value.trim();if(/^20\d{2}-\d{2}-\d{2}$/.test(s))return null;const zoned=/[zZ]$|[+-]\d{2}:?\d{2}$/.test(s);const parsed=Date.parse(zoned?s:(/^20\d{2}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(s)?s.replace(' ','T')+'+07:00':s));return Number.isFinite(parsed)?Math.floor(parsed/1000):null;}let n=Number(value);if(!Number.isFinite(n)||n<=0)return null;if(n>1e14)n/=1e6;else if(n>1e11)n/=1000;const now=Math.floor(Date.now()/1000);return n>=1420070400&&n<=now+366*86400?Math.floor(n):null;}
function getPath(o,p){return p.split('.').reduce((v,k)=>v?.[k],o);}
function firstEpoch(obj,paths){for(const p of paths){const e=normalizeEpochSeconds(getPath(obj,p));if(e)return {epoch:e,path:p};}return null;}
function formatBangkok(epoch){if(!epoch)return '';const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Bangkok',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date(epoch*1000));const m=Object.fromEntries(parts.map(x=>[x.type,x.value]));return `${m.year}-${m.month}-${m.day} ${m.hour}:${m.minute}:${m.second}`;}
function dateOnly(epoch){return formatBangkok(epoch).slice(0,10);}
function parseShopeeCreated(value){
  if(value==null||value==='')return null;
  const s=typeof value==='string'?value.trim():'';
  const m=/^(20\d{2}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}(?::\d{2})?)(Z|[+-]\d{2}:?\d{2})?)?$/.exec(s);
  if(m){
    const valid=new Date(m[1]+'T00:00:00Z');
    if(!Number.isFinite(valid.getTime())||valid.toISOString().slice(0,10)!==m[1])return null;
    if(m[2] && !/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(m[2]))return null;
    if(!m[2])return {date:m[1],datetime:'',epoch:null,precision:'date'};
    if(!m[3])return {date:m[1],datetime:m[1]+' '+(m[2].length===5?m[2]+':00':m[2]),epoch:null,precision:'local_time_without_zone'};
  }
  const epoch=normalizeEpochSeconds(value);
  if(!epoch)return null;
  const datetime=formatBangkok(epoch);
  return {date:datetime.slice(0,10),datetime,epoch,precision:'timestamp'};
}
// Candidate raw delivery timestamps are unverified in the Shopee Buyer API.
// Keep provenance only; NEVER use them to mark Detail incomplete or display a received date.
const COURIER_DELIVERED_FIELDS=['delivered_time','actual_delivery_time','delivery_completed_time','courier_delivered_at','carrier_delivered_at','parcel_delivered_at'];
function deliveryFieldIsConfirmed(path,key){return COURIER_DELIVERED_FIELDS.includes(key) && !/(estimated|estimate|expected|predict|plan|promise|pickup|dispatch|handover|warehouse|shipout|shipped|buyer_received|order_complete)/i.test(path);}
function deepFind(obj,names,maxDepth=7){const wanted=new Set(names);const seen=new Set();function walk(v,path,depth){if(depth>maxDepth||v==null||typeof v!=='object'||seen.has(v))return null;seen.add(v);for(const [k,val] of Object.entries(v)){const p=path?path+'.'+k:k;if(wanted.has(k)&&val!==null&&val!==''&&typeof val!=='object')return {value:val,path:p};if(val&&typeof val==='object'){const f=walk(val,p,depth+1);if(f)return f;}}return null;}return walk(obj,'',0);}
function deepFindWhere(obj,names,predicate,maxDepth=7){const wanted=new Set(names),seen=new Set();function walk(v,path,depth){if(depth>maxDepth||v==null||typeof v!=='object'||seen.has(v))return null;seen.add(v);for(const [k,val] of Object.entries(v)){const p=path?path+'.'+k:k;if(wanted.has(k)&&val!==null&&val!==''&&typeof val!=='object'&&predicate(p,k))return {value:val,path:p};if(val&&typeof val==='object'){const f=walk(val,p,depth+1);if(f)return f;}}return null;}return walk(obj,'',0);}
function deepMoney(obj,names){const f=deepFind(obj,names);return f?num(f.value):null;}
function cleanText(v){if(v==null||typeof v==='object')return '';const s=String(v).trim();return s.length<=240?s:'';}
function normalizeFamilyName(v){return txt(v).toLowerCase().replace(/[\s\u00a0]+/g,' ').replace(/[|•·]+/g,' ').trim().slice(0,220);}
function objectText(v){if(v==null)return '';if(typeof v!=='object')return cleanText(v);for(const k of ['display_name','name','label','text','title','channel_name','method_name','carrier_name','logistics_channel_name']){const x=cleanText(v?.[k]);if(x)return x;}return '';}
function deepTextWhere(obj,names,predicate,maxDepth=8){const f=deepFindWhere(obj,names,predicate,maxDepth);if(f)return {value:objectText(f.value)||cleanText(f.value),path:f.path};const wanted=new Set(names),seen=new Set();function walk(v,path,depth){if(depth>maxDepth||v==null||typeof v!=='object'||seen.has(v))return null;seen.add(v);for(const [k,val] of Object.entries(v)){const pp=path?path+'.'+k:k;if(wanted.has(k)&&(!predicate||predicate(pp,k))){const text=objectText(val);if(text)return {value:text,path:pp};}if(val&&typeof val==='object'){const z=walk(val,pp,depth+1);if(z)return z;}}return null;}return walk(obj,'',0);}
// Prefer actual human-readable method names over Shopee's opaque payment_method integer.
// Numeric codes (e.g. 6/92) are NOT mapped without a verified Shopee contract.
function paymentDetailInfo(obj,maxDepth=8){
  const keys=new Set(['payment_method','payment_method_name','payment_channel_name','payment_channel','payment_display_name','checkout_payment_method','checkout_payment_method_name','method_name']);
  const explicitKeys=new Set(['payment_method_name','payment_channel_name','payment_display_name','checkout_payment_method_name','method_name']);
  const seen=new Set();let explicit=null,descriptive=null,code=null;
  function walk(v,path,depth){
    if(depth>maxDepth||!v||typeof v!=='object'||seen.has(v))return;
    seen.add(v);
    for(const [key,val] of Object.entries(v)){
      const p=path?path+'.'+key:key;
      if(keys.has(key)&&/(payment|checkout|pay_)/i.test(p)){
        const candidate=objectText(val)||cleanText(val);
        if(candidate){
          const result={value:candidate,path:p};
          if(/^[0-9]+$/.test(candidate)){if(!code)code=result;}
          else if(explicitKeys.has(key)||(val&&typeof val==='object')){if(!explicit)explicit=result;}
          else if(!descriptive)descriptive=result;
        }
      }
      if(val&&typeof val==='object')walk(val,p,depth+1);
    }
  }
  walk(obj,'',0);
  const result=explicit||descriptive||code;
  return result?{...result,rawCode:code?.value||''}:null;
}
function detailMeta(json){
  const d=json?.data||{};
  const created=extractBestListDate(d);
  const paid=firstEpoch(d,['pc_processing_info.pay_time','pay_time','payment_time','paid_time','info_card.pay_time','payment_info.pay_time']);
  const completed=firstEpoch(d,['pc_processing_info.complete_time','complete_time','completed_time','order_complete_time','complete_info.complete_time']);
  const deliveredFound=deepFindWhere(d,COURIER_DELIVERED_FIELDS,deliveryFieldIsConfirmed);
  const delivered=deliveredFound?parseShopeeCreated(deliveredFound.value):null;
  const payment=paymentDetailInfo(d);
  const carrier=deepTextWhere(d,['shipping_carrier','carrier_name','logistics_channel_name','shipping_channel_name','logistics_channel','logistic_channel','channel_name'],p=>/(shipping|shipment|parcel|tracking|logistic|fulfillment|delivery)/i.test(p));
  const tracking=deepTextWhere(d,['tracking_number','tracking_no','tracking_number_list','tracking_code','tracking_id'],p=>/(tracking|shipping|shipment|parcel|logistic)/i.test(p));
  const parcelCount=Number(d?.shipping?.num_parcels??d?.num_parcels??d?.parcel_count??(Array.isArray(d?.package_list)?d.package_list.length:(Array.isArray(d?.packages)?d.packages.length:0)))||0;
  const meta={order_created_at:created?.datetime||created?.date||'',paid_at:formatBangkok(paid?.epoch),delivered_at:delivered?.datetime||delivered?.date||'',completed_at:formatBangkok(completed?.epoch),delivery_date_source:delivered?`detail.${deliveredFound.path}`:'',payment_method:cleanText(payment?.value),shipping_carrier:cleanText(carrier?.value),tracking_number:cleanText(tracking?.value),parcel_count:parcelCount,shipping_fee:deepMoney(d,['actual_shipping_fee','buyer_shipping_fee'])??0,voucher_discount:deepMoney(d,['voucher_discount','voucher_discount_amount'])??0,coins_discount:deepMoney(d,['coins_discount','coin_discount'])??0,platform_discount:deepMoney(d,['platform_discount'])??0,detail_enriched:1,detail_error:''};
  // Buyer carrier name is not contract-backed. Keep any raw carrier, but never require it for Detail coverage.
  const missing=[];meta.detail_missing_fields=missing.join(',');
  meta.metadata_json=JSON.stringify({sources:{created:created?.path||'',paid:paid?.path||'',delivered:deliveredFound?.path||'',completed:completed?.path||'',payment:payment?.path||'',carrier:carrier?.path||'',tracking:tracking?.path||''},codes:{payment_method:payment?.rawCode||''},values:{order_created_at:meta.order_created_at,paid_at:meta.paid_at,delivered_at:meta.delivered_at,completed_at:meta.completed_at,payment_method:meta.payment_method,shipping_carrier:meta.shipping_carrier,tracking_number:meta.tracking_number,parcel_count:meta.parcel_count},missing});
  return meta;
}
function extractBestListDate(d){for(const p of ['info_card.order_create_time','info_card.create_time','order_create_time','create_time','pc_processing_info.create_time']){const event=parseShopeeCreated(getPath(d,p));if(event)return {...event,label:'order creation time',path:p};}return null;}
function extractOrderIdentity(d,info,cards){for(const [source,val] of [['info_card.order_id',info?.order_id],['info_card.order_sn',info?.order_sn],['detail.order_id',d?.order_id],['detail.order_sn',d?.order_sn],['card.order_id',cards?.[0]?.order_id],['card.order_sn',cards?.[0]?.order_sn]])if(val!==undefined&&val!==null&&String(val).trim()!=='')return {id:String(val).trim(),source};return null;}
/** Qty must come from a purchased-item field. Never silently invent one piece. */
function purchasedItemQuantity(card){
  const known=['amount','quantity_purchased','model_quantity_purchased','purchased_quantity','quantity'];
  let chosen=null,source='';
  for(const field of known){
    const raw=card?.[field];
    if(raw===undefined||raw===null||raw==='')continue;
    const n=Number(raw);
    if(!Number.isSafeInteger(n)||n<1||n>100000)return {ok:false,reason:'invalid_quantity',field};
    if(chosen!==null&&chosen!==n)return {ok:false,reason:'conflicting_quantity_fields',field};
    if(chosen===null){chosen=n;source=field;}
  }
  return chosen===null?{ok:false,reason:'missing_quantity'}:{ok:true,quantity:chosen,field:source};
}
/** 64-bit FNV-1a for stable SKU identity when a Shopee variant has no model_id. */
function lineIdentityHash(s){
  let hash=0xcbf29ce484222325n;
  for(const ch of String(s)){const code=ch.codePointAt(0);hash^=BigInt(code);hash=BigInt.asUintN(64,hash*0x100000001b3n);}
  return hash.toString(16).padStart(16,'0');
}
function normalizedVariantText(v){return txt(v).trim().toLocaleLowerCase('en').replace(/\s+/g,' ');}
function productLineKey(shopId,itemId,modelId,name,variant){
  const identity=txt(itemId||'').trim()||`name-${lineIdentityHash(name)}`;
  const model=txt(modelId||'').trim();
  if(model&&model!=='0')return `shopee:${txt(shopId||0)}:${identity}:${model}`;
  const v=normalizedVariantText(variant);
  return `shopee:${txt(shopId||0)}:${identity}:${v?'variant-'+lineIdentityHash(v):'0'}`;
}
/** Parsed snapshot is all-or-nothing so DB never prunes good variants from an ambiguous page. */
function normalizeOrder(raw,account,dateOverride=null,detail={},options={}){
  const d=raw||{},info=d?.info_card||{};
  const cards=Array.isArray(info?.order_list_cards)?info.order_list_cards:[];
  if(!cards.length)return {orderNo:'',items:[],ignoredReason:'missing_order_cards'};
  const identity=extractOrderIdentity(d,info,cards);
  if(!identity)return {orderNo:'',items:[],ignoredReason:'missing_order_identity'};
  const listType=Number(d?.list_type);
  if(![3,4,7,8,9,12].includes(listType))return {orderNo:identity.id,items:[],ignoredReason:'unknown_list_type'};
  if(listType===4)return {orderNo:identity.id,items:[],ignoredReason:'cancelled_order'};
  const dt=dateOverride||extractBestListDate(d);
  const orderDate=dt?.date||(dt?.epoch?dateOnly(dt.epoch):'');
  const createdAt=dt?.datetime||(dt?.epoch?formatBangkok(dt.epoch):'');
  const shop=cards[0]?.shop_info||{};
  const shopId=shop?.shop_id??shop?.shopid??cards[0]?.shop_id??d?.shop_id??null;
  const shopName=txt(shop?.shop_name||shop?.username||d?.shop_name||'');
  if(!shopName)return {orderNo:identity.id,items:[],ignoredReason:'missing_shop'};
  // A purchased line is NOT synonymous with a SKU. Two distinct rows with the
  // same shop/item/model (for example split shipments or repeated variants)
  // must remain independently stored. The stable source path is carried in the
  // product key because the legacy SQL UNIQUE(order_id, product_key) is kept.
  const flat=[];
  for(let ci=0;ci<cards.length;ci++){
    const c=cards[ci];
    const subShopId=c?.shop_info?.shop_id??c?.shop_info?.shopid??c?.shop_id??shopId;
    if(shopId!=null&&subShopId!=null&&String(subShopId)!==String(shopId))
      return {orderNo:identity.id,items:[],ignoredReason:'mixed_shop_cards'};
    const groups=c?.product_info?.item_groups;
    if(Array.isArray(groups))for(let gi=0;gi<groups.length;gi++){
      const group=groups[gi];
      if(Array.isArray(group?.items))for(let ii=0;ii<group.items.length;ii++){
        flat.push({card:group.items[ii],path:`card:${ci}/group:${gi}/item:${ii}`});
      }
    }
  }
  if(!flat.length)return {orderNo:identity.id,items:[],ignoredReason:'missing_items'};
  const prepared=[];let returnedQty=0;
  for(const row of flat){
    const card=row.card;
    const count=purchasedItemQuantity(card);
    if(!count.ok)return {orderNo:identity.id,items:[],ignoredReason:count.reason};
    if(Number(card?.status)===3){returnedQty+=count.quantity;continue;}
    const itemId=card?.item_id??card?.itemid??null;
    const modelId=card?.model_id??card?.modelid??card?.variation_id??0;
    const name=txt(card?.name||card?.item_name||'').trim();
    const variant=txt(card?.model_name||card?.variation||card?.variation_name||'').trim();
    const sell=num(card?.item_price??card?.model_discounted_price??card?.model_original_price??card?.price??0);
    if(!name||!Number.isFinite(sell)||sell<0)return {orderNo:identity.id,items:[],ignoredReason:'missing_valid_items'};
    const original=num(card?.original_price??card?.model_original_price??card?.price_before_discount??card?.item_price??0);
    const skuKey=productLineKey(shopId,itemId,modelId,name,variant);
    const explicitLineId=txt(card?.order_item_id??card?.order_line_id??card?.line_item_id??card?.line_id??'');
    const key=`${skuKey}:line-${lineIdentityHash(row.path)}`;
    prepared.push({card,itemId,modelId,name,variant,qty:count.quantity,sell,original,
      line:sell*count.quantity,key,sourceLinePath:row.path,sourceLineId:explicitLineId,
      qtySources:[count.field]});
  }
  if(!prepared.length)return {orderNo:identity.id,items:[],ignoredReason:'missing_valid_items'};
  const itemQty=prepared.reduce((sum,p)=>sum+p.qty,0);
  const announced=Number(info?.product_count);
  // product_count may mean distinct lines or purchased units across market regions.
  // It is still a strong shortage signal if it exceeds ALL observed units,
  // including skipped returned lines. Never invent extra items to fill the gap.
  if(info?.product_count!==undefined&&info?.product_count!==null&&info?.product_count!==''&&
      Number.isSafeInteger(announced)&&announced>itemQty+returnedQty)
    return {orderNo:identity.id,items:[],ignoredReason:'product_count_exceeds_snapshot'};
  const rawSubtotal=prepared.reduce((sum,p)=>sum+p.line,0);
  const finalTotal=num(info?.final_total??info?.total_payable??info?.subtotal??0);
  let shippingCandidate=null;
  for(const k of ['actual_shipping_fee','buyer_shipping_fee']){
    const f=deepFind(info,[k],4);if(f){shippingCandidate=num(f.value);break;}
  }
  const shippingFee=shippingCandidate==null?0:Math.max(0,shippingCandidate);
  let merchandisePaid=null,pricingMethod='order_total_only_unallocated';
  if(finalTotal>0&&shippingCandidate!=null&&finalTotal>=shippingFee){
    const candidate=finalTotal-shippingFee;
    if(candidate>=0&&candidate<=rawSubtotal*1.05){merchandisePaid=Math.min(rawSubtotal,candidate);pricingMethod='final_total_minus_shipping_proportional';}
  }else if(finalTotal>0&&finalTotal<=rawSubtotal){merchandisePaid=finalTotal;pricingMethod='final_total_proportional';}
  const ratio=merchandisePaid!=null&&rawSubtotal>0?merchandisePaid/rawSubtotal:1;
  const discountTotal=merchandisePaid!=null?Math.max(0,rawSubtotal-merchandisePaid):0;
  const status=statusFromListType(listType),purchaseState=[3,7,8].includes(listType)?'purchase':'non_purchase';
  const out=[];
  const itemSnapshotSource=options?.source==='buyer_detail_complete'?'buyer_detail_complete':'buyer_order_list_preview';
  for(const p of prepared){
    // Unit price in Buyer Detail is the actual product price Shopee provided.
    // Do not present a proportional allocation of whole-order vouchers/coins
    // as if it were a confirmed per-product checkout price.
    const directItemPrice=itemSnapshotSource==='buyer_detail_complete';
    const actualLine=directItemPrice?p.line:p.line*ratio;
    const actualUnit=directItemPrice?p.sell:actualLine/p.qty;
    const card=p.card,img=card?.image??card?.image_url??card?.image_info??'';
    const image=typeof img==='object'?(img?.image_url||img?.image_id||''):img;
    const categoryId=txt(card?.category_id??card?.catid??card?.category?.category_id??card?.category?.catid??'');
    const categoryName=txt(card?.category_name??card?.category?.name??card?.category?.display_name??'');
    const categoryPath=txt(card?.category_path??card?.category?.path??'');
    const familyName=normalizeFamilyName(p.name);
    out.push({platform:'shopee_th',order_no:identity.id,order_date:orderDate,order_created_at:createdAt,
      shop_name:shopName,product_key:p.key,product_name:p.name,variant_name:p.variant,
      item_snapshot_source:itemSnapshotSource,item_snapshot_complete:itemSnapshotSource==='buyer_detail_complete'?1:0,
      image_url:imageUrl(image),product_url:productUrl(shopId,p.itemId),quantity:p.qty,
      original_price:p.original,purchase_price:p.sell,net_unit_price:actualUnit,
      actual_unit_price:actualUnit,actual_line_total:actualLine,
      allocated_discount:directItemPrice?0:Math.max(0,p.line-actualLine),total_paid:Math.max(0,finalTotal),
      raw_subtotal:rawSubtotal,subtotal:rawSubtotal,merchandise_paid:merchandisePaid==null?0:merchandisePaid,
      shipping_fee:shippingFee,voucher_discount:0,coins_discount:0,platform_discount:0,
      discount_total:directItemPrice?0:discountTotal,
      pricing_method:directItemPrice?'buyer_detail_unit_price_unallocated':pricingMethod,
      order_status:status,list_type:listType,
      purchase_state:purchaseState,validation_state:'verified_v200',
      source_account_id:txt(account?.userid||''),source_account_username:txt(account?.username||account?.nickname||''),
      date_source:dt?.path||'unknown',identity_source:identity.source,
      marketplace_shop_id:txt(shopId||''),marketplace_item_id:txt(p.itemId||''),marketplace_model_id:txt(p.modelId||''),
      marketplace_category_id:categoryId,marketplace_category_name:categoryName,
      marketplace_category_path:categoryPath,category_source:categoryName||categoryId?'order_list':'',
      category_updated_at:categoryName||categoryId?formatBangkok(Math.floor(Date.now()/1000)):'',
      product_family_key:familyName?`name:${familyName}`:p.key,product_family_name:p.name,...detail,
      raw_json:JSON.stringify({order_id:identity.id,list_type:listType,order_status:status,
        date_source:dt?.path||'unknown',shop_id:shopId,item_id:p.itemId,model_id:p.modelId,
        category_id:categoryId,category_name:categoryName,item_price:p.sell,
        quantity:p.qty,quantity_sources:p.qtySources,source_line_path:p.sourceLinePath,
        source_line_id:p.sourceLineId,item_snapshot_source:itemSnapshotSource,
        source_product_count:Number.isSafeInteger(announced)?announced:null,
        normalized_total_quantity:itemQty,final_total:finalTotal,pricing_method:pricingMethod,
        source_account_id:txt(account?.userid||'')})});
  }
  return {orderNo:identity.id,items:out,itemQuantity:itemQty,sourceLines:flat.length,
    itemSnapshotSource,sourceProductCount:Number.isSafeInteger(announced)?announced:null,ignoredReason:''};
}

/** Get item rows from a buyer Order Detail response, not from the condensed
 * Order List. If Shopee changes shape, do not declare a complete snapshot.
 * This never uses seller Open Platform endpoints or private browser cookies.
 */
function buyerDetailItemCandidate(json,listRecord,account){
  const data=json?.data;
  if(!data||typeof data!=='object')return {status:'unavailable',reason:'detail_missing_data'};
  const base=listRecord||{};
  const baseInfo=base?.info_card||{};
  const expected=txt(baseInfo.order_id??baseInfo.order_sn??base.order_id??base.order_sn);
  const nodes=[
    ['data',data],['data.order_detail',data?.order_detail],
    ['data.order_list_detail',data?.order_list_detail],['data.order_info',data?.order_info],
    ['data.order_data',data?.order_data],['data.new_data',data?.new_data]
  ];
  const candidates=[];
  for(const [path,v] of nodes){
    if(!v||typeof v!=='object'||Array.isArray(v))continue;
    const info=v.info_card||{};
    const reported=txt(info.order_id??info.order_sn??v.order_id??v.order_sn);
    if(reported&&expected&&reported!==expected)return {status:'error',reason:'buyer_detail_identity_mismatch'};
    let cards=info.order_list_cards||v.order_list_cards;
    if(!Array.isArray(cards)||!cards.length){
      if(Array.isArray(v?.product_info?.item_groups)){
        cards=[{shop_info:baseInfo?.order_list_cards?.[0]?.shop_info||v.shop_info||{},
          product_info:{item_groups:v.product_info.item_groups}}];
      }else if(Array.isArray(v.item_list)&&v.item_list.length){
        cards=[{shop_info:baseInfo?.order_list_cards?.[0]?.shop_info||v.shop_info||{},
          product_info:{item_groups:[{items:v.item_list}]}}];
      }else if(Array.isArray(v.package_list)&&v.package_list.length){
        const groups=v.package_list.map(p=>({items:p?.item_list||p?.items||[]})).filter(g=>Array.isArray(g.items)&&g.items.length);
        if(groups.length)cards=[{shop_info:baseInfo?.order_list_cards?.[0]?.shop_info||v.shop_info||{},product_info:{item_groups:groups}}];
      }
    }
    if(!Array.isArray(cards)||!cards.length)continue;
    // The list product_count is not authoritative for independent Buyer Detail.
    const detailInfo={...baseInfo,...info,order_id:expected||reported,order_list_cards:cards};
    if(info.product_count===undefined&&v.product_count===undefined)delete detailInfo.product_count;
    else if(info.product_count===undefined)detailInfo.product_count=v.product_count;
    const record={...base,...v,list_type:Number(base.list_type??v.list_type),info_card:detailInfo};
    // Metadata from the same response may have no recognized order timestamp;
    // preserve the known order-creation value from the list instead.
    const dt=extractBestListDate(record)||extractBestListDate(base);
    const norm=normalizeOrder(record,account,dt,{}, {source:'buyer_detail_complete'});
    if(norm.ignoredReason)return {status:'error',reason:'buyer_detail_'+norm.ignoredReason};
    // A declared count can mean units or rows; both interpretations must not
    // contradict the detail. Never fill missing quantity with a guessed value.
    const declared=Number(info.product_count??v.product_count);
    if(Number.isSafeInteger(declared)&&declared>0&&
       declared>norm.itemQuantity&&declared>norm.items.length)
      return {status:'error',reason:'buyer_detail_count_exceeds_items'};
    candidates.push({path,record,normalized:norm});
  }
  if(!candidates.length)return {status:'unavailable',reason:'buyer_detail_has_no_items'};
  // Nested representations of one order may differ. Never choose the longest
  // array by guesswork; a disagreement is an explicit data-quality failure.
  if(candidates.length>1){
    const fingerprints=candidates.map(c=>JSON.stringify(c.normalized.items.map(i=>[
      i.marketplace_item_id,i.marketplace_model_id,i.variant_name,i.quantity,i.purchase_price
    ])));
    if(new Set(fingerprints).size!==1)return {status:'error',reason:'conflicting_buyer_detail_item_representations'};
  }
  return {status:'complete',...candidates[0]};
}

/** Authenticated buyer detail reads precede a page's database mutation. A
 * metadata-only response is visibly marked unverified rather than invented.
 */
async function resolveBuyerItemSnapshot(tabId,order,account){
  const no=txt(order?.info_card?.order_id??order?.info_card?.order_sn??order?.order_id??order?.order_sn);
  if(!no)throw new Error('Buyer order identity missing during detail audit');
  if(String((await accountForTab(tabId)).userid)!==String(account.userid))
    throw new Error('SESSION_BLOCK Shopee account switched before item audit');
  const res=await mainWorldDetail(tabId,no);
  const code=Number(res?.json?.error||0);
  if([401,403,429].includes(Number(res?.http))||code===90309999)
    throw new Error(`SESSION_BLOCK Shopee detail denied HTTP ${res?.http||0} code ${code}`);
  if(!res?.ok||code!==0||!res?.json?.data)
    return {status:'unavailable',reason:'detail_unavailable'};
  const candidate=buyerDetailItemCandidate(res.json,order,account);
  if(candidate.status==='complete' && String((await accountForTab(tabId)).userid)!==String(account.userid))
    throw new Error('SESSION_BLOCK Shopee account switched after item audit');
  return {...candidate,detailJson:res.json};
}

async function getAllStates(){return (await chrome.storage.local.get('syncStates')).syncStates||{};}
async function getState(accountId=''){const all=await getAllStates();return accountId?all[String(accountId)]||{}:(await chrome.storage.local.get('lastAccountId')).lastAccountId?all[String((await chrome.storage.local.get('lastAccountId')).lastAccountId)]||{}:{};}
async function setState(accountId,patch){const all=await getAllStates(),key=String(accountId),next={...(all[key]||{}),...patch,accountId:key,updatedAt:Date.now()};all[key]=next;await chrome.storage.local.set({syncStates:all,lastAccountId:key});chrome.runtime.sendMessage({type:'SYNC_PROGRESS',state:next}).catch(()=>{});return next;}
async function hubJson(hub,path,opts={}){const cfg=await chrome.storage.local.get('apiKey');opts={...opts,headers:{...(opts.headers||{}),'X-PAN-Key':cfg.apiKey||''}};const r=await fetch(hub.replace(/\/$/,'')+path,opts);const t=await r.text();let j;try{j=JSON.parse(t)}catch{throw new Error(`PAN HTTP ${r.status}: ${t.slice(0,180)}`)}if(!r.ok||j.ok===false)throw new Error(j.error||`PAN HTTP ${r.status}`);return j;}
async function postBatch(hub,items,meta){if(!items.length)return {};return hubJson(hub,'/api/import.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({source:'shopee_extension_v2',source_url:meta.url,job_type:meta.jobType||'sync',scan_id:meta.scanId,items})});}
async function enrichOne(hub,tabId,account,orderNo,listType=0){const r=await mainWorldDetail(tabId,orderNo),apiError=Number(r?.json?.error||0);if(r?.http===401||r?.http===403)throw new Error(`SESSION_BLOCK HTTP ${r.http}`);if(apiError===90309999)throw new Error('SESSION_BLOCK Shopee anti-fraud 90309999');if(apiError!==0)throw new Error(`Shopee API error ${apiError}`);if(!r?.ok||!r?.json?.data)throw new Error(`HTTP ${r?.http||0} / detail data empty`);const meta=detailMeta(r.json);return hubJson(hub,'/api/enrich.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({order_no:String(orderNo),list_type:Number(listType||0),source_account_id:String(account.userid),source_account_username:account.username||account.nickname||'',order_status:statusFromListType(listType),...meta})});}
async function autoEnrichOrders(hub,tabId,account,rows,cachedMetas=new Map()){
  let ok=0,errors=0,lastError='';
  for(const row of rows.slice(0,30)){
    try{
      if(String((await accountForTab(tabId)).userid)!==String(account.userid))
        throw new Error('SESSION_BLOCK บัญชี Shopee เปลี่ยนระหว่างเติมรายละเอียด');
      const cached=cachedMetas.get(String(row.order_no));
      if(cached){
        await hubJson(hub,'/api/enrich.php',{method:'POST',headers:{'content-type':'application/json'},
          body:JSON.stringify({order_no:String(row.order_no),list_type:Number(row.list_type||0),
            source_account_id:String(account.userid),source_account_username:account.username||account.nickname||'',
            order_status:statusFromListType(row.list_type),...cached})});
      }else await enrichOne(hub,tabId,account,row.order_no,row.list_type);
      ok++;
    }catch(e){lastError=String(e.message||e);errors++;
      if(lastError.startsWith('SESSION_BLOCK'))throw new Error(lastError);}
    await sleep(180);
  }
  return {ok,errors,lastError};
}
async function postCancelled(hub,accountId,orderNos){if(!orderNos.length)return {deleted:0};return hubJson(hub,'/api/cancelled.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({account_id:String(accountId),order_nos:[...new Set(orderNos.map(String))]})});}
async function accountForTab(tabId){const ai=await mainWorldAccount(tabId);if(!ai?.ok||!ai?.account?.userid)throw new Error(`ยืนยัน Shopee User ID ไม่สำเร็จ · HTTP ${ai?.http??0} · error ${ai?.json_error??''}`);return ai.account;}
async function resolveDetail(tabId,orderId){const r=await mainWorldDetail(tabId,orderId);if(r?.http===401||r?.http===403)throw new Error(`Shopee HTTP ${r.http} Order Detail`);if(Number(r?.json?.error||0)===90309999)throw new Error('Shopee anti-fraud 90309999 ที่ Order Detail');if(!r?.ok||!r?.json?.data)return null;return detailMeta(r.json);}

// An unreadable item list is one unresolved Order, NOT a reason to throw away
// the other 19 valid Orders on its page. Save only minimal identifiers in the
// account-scoped local Extension state; no raw buyer payload, cookies or address.
const INVALID_ITEM_REASONS=new Set([
  'missing_order_cards','missing_shop','missing_items','missing_valid_items',
  'missing_quantity','invalid_quantity','conflicting_quantity_fields',
  'product_identity_collision','product_count_exceeds_snapshot','mixed_shop_cards'
]);
const PENDING_ITEM_LIMIT=2500;
function pendingItemShape(raw){
  const cards=raw?.info_card?.order_list_cards;
  if(!Array.isArray(cards))return {cards:0,groups:0,rows:0,itemKeys:[]};
  let groups=0,rows=0;const keys=new Set();
  for(const c of cards){for(const g of c?.product_info?.item_groups||[]){
    groups++;if(!Array.isArray(g?.items))continue;
    for(const item of g.items){rows++;
      if(item&&typeof item==='object')for(const k of Object.keys(item)){
        // Field names only, not item values, buyer details or credentials.
        if(/^(?:item_|model_|variation|variant|amount|quantity|price|name|status|order_|line_)/.test(k))keys.add(k);
      }
    }
  }}
  return {cards:cards.length,groups,rows,itemKeys:[...keys].sort().slice(0,24)};
}
function pendingOrderReference(raw,orderNo,reason){
  const no=txt(orderNo).trim();
  if(!/^[a-zA-Z0-9_-]{6,100}$/.test(no))return null;
  const info=raw?.info_card||{},cards=info?.order_list_cards||[];
  const card=Array.isArray(cards)?cards[0]||{}:{};
  const shop=card?.shop_info||{};
  const itemCount=Number(info?.product_count);
  return {orderNo:no,listType:Number(raw?.list_type||0),reason:txt(reason).slice(0,90),
    shopId:txt(shop?.shop_id??shop?.shopid??card?.shop_id??raw?.shop_id??'').slice(0,80),
    shopName:txt(shop?.shop_name??shop?.username??raw?.shop_name??'').slice(0,180),
    productCount:Number.isSafeInteger(itemCount)&&itemCount>=0?itemCount:null,shape:pendingItemShape(raw)};
}
function minimalOrderForPending(ref){
  return {list_type:Number(ref.listType||0),order_id:ref.orderNo,info_card:{
    order_id:ref.orderNo,product_count:ref.productCount,
    order_list_cards:[{shop_info:{shop_id:ref.shopId,shop_name:ref.shopName},
      product_info:{item_groups:[]}}]}};
}
function pendingReasonCounts(rows){
  const counts={};for(const r of rows||[]){const key=txt(r.reason)||'unresolved';
    counts[key]=(counts[key]||0)+1;}return counts;
}
function mergePendingOrders(existing,added,resolved){
  const completed=new Set((resolved||[]).map(String));const indexed=new Map();
  for(const row of [...(Array.isArray(existing)?existing:[]),...(added||[])]){
    const no=txt(row?.orderNo);if(!no||completed.has(no))continue;
    indexed.set(no,row);
  }
  if(indexed.size>PENDING_ITEM_LIMIT)
    throw new Error('จำนวนออเดอร์ที่ค้างตรวจเกินขีดจำกัด · ไม่เลื่อน checkpoint');
  return [...indexed.values()];
}
function partialSyncText(pending){return `สแกนรายการอื่นแล้ว · ค้างตรวจสินค้า ${pending} Order · ลองอ่านใหม่จากปุ่มรายการค้าง (ยังไม่ถือว่าซิงก์ครบ)`;}
let runningJob=null;
async function processSyncRecords(raw,account,hub,scanId,pageUrl,seenOrderNos=[],options={}){
  const batch=[],cancelledNos=[],pendingItemOrders=[],resolvedOrderNos=[];
  let orderCount=0,ignored=0,cancelled=0,dateUnknown=0,newUnique=0,duplicateRecords=0;
  const reasonCounts={},pageOrderSnapshots=new Map(),detailMetas=new Map();
  let buyerDetailComplete=0,buyerDetailPreview=0;
  const seen=new Set((Array.isArray(seenOrderNos)?seenOrderNos:[]).map(String));
  const canQuarantine=Boolean(options.autoEnrich&&options.tabId);
  function addPending(record,orderNo,reason){
    if(!canQuarantine||!INVALID_ITEM_REASONS.has(reason)&&!String(reason).startsWith('buyer_detail_'))
      throw new Error(`Shopee schema คำสั่งซื้อไม่ตรง · ${reason} · ไม่เลื่อน checkpoint`);
    const reference=pendingOrderReference(record,orderNo,reason);
    if(!reference)throw new Error('Shopee schema ไม่ทราบรหัส Order · ไม่เลื่อน checkpoint');
    pendingItemOrders.push(reference);ignored++;
    reasonCounts[reason]=(reasonCounts[reason]||0)+1;
  }
  for(const r of raw){
    let n=normalizeOrder(r,account);
    if(n.orderNo){const k=String(n.orderNo);if(seen.has(k))duplicateRecords++;else{seen.add(k);newUnique++;}}
    if(n.ignoredReason==='cancelled_order'){
      cancelled++;ignored++;if(n.orderNo){cancelledNos.push(n.orderNo);resolvedOrderNos.push(String(n.orderNo));}
      reasonCounts.cancelled_order=(reasonCounts.cancelled_order||0)+1;continue;
    }
    if(!extractBestListDate(r))dateUnknown++;
    const knownOrderId=txt(n.orderNo||r?.info_card?.order_id||r?.info_card?.order_sn||r?.order_id||r?.order_sn);
    if(n.ignoredReason==='unknown_list_type')throw new Error('Shopee ส่งประเภท Order ที่ยังไม่รองรับ · ไม่เลื่อน checkpoint');
    if(!knownOrderId){
      // Without an identity we cannot keep a recoverable pending reference.
      if(INVALID_ITEM_REASONS.has(n.ignoredReason)||n.ignoredReason==='missing_order_identity')
        throw new Error('Shopee ไม่ระบุ Order identity · ไม่เลื่อน checkpoint');
      ignored++;reasonCounts[n.ignoredReason||'unknown']=(reasonCounts[n.ignoredReason||'unknown']||0)+1;
      continue;
    }
    const listFingerprint=JSON.stringify({reason:n.ignoredReason||'',
      rows:n.items?.map(x=>[x.product_key,x.variant_name,x.quantity,x.purchase_price])||[]});
    const previous=pageOrderSnapshots.get(knownOrderId);
    if(previous!==undefined){
      if(previous!==listFingerprint)throw new Error('Conflicting duplicate Order item snapshots on same page; no import/checkpoint');
      continue;
    }
    pageOrderSnapshots.set(knownOrderId,listFingerprint);
    if(options.autoEnrich&&options.tabId){
      const detailSnapshot=await resolveBuyerItemSnapshot(options.tabId,r,account);
      if(detailSnapshot.status==='error'){
        // Cross-order identity confusion is a security stop, not a retriable
        // product parsing mismatch. Other detail-shape failures are isolated.
        if(detailSnapshot.reason==='buyer_detail_identity_mismatch')
          throw new Error('Buyer Order Detail identity mismatch · ไม่เลื่อน checkpoint');
        addPending(r,knownOrderId,detailSnapshot.reason);continue;
      }
      if(detailSnapshot.status==='complete'){
        n=detailSnapshot.normalized;
        if(detailSnapshot.detailJson)detailMetas.set(String(n.orderNo),detailMeta(detailSnapshot.detailJson));
        buyerDetailComplete++;
      }else buyerDetailPreview++;
      await sleep(110);
    }
    if(n.items.length){orderCount++;batch.push(...n.items);resolvedOrderNos.push(knownOrderId);}
    else if(INVALID_ITEM_REASONS.has(n.ignoredReason))addPending(r,knownOrderId,n.ignoredReason);
    else if(n.ignoredReason==='missing_order_identity')throw new Error('Shopee ไม่ระบุ Order identity · ไม่เลื่อน checkpoint');
    else{ignored++;const reason=n.ignoredReason||'unknown';reasonCounts[reason]=(reasonCounts[reason]||0)+1;}
  }
  if(pendingItemOrders.length>PENDING_ITEM_LIMIT)
    throw new Error('รายการค้างตรวจสินค้าเกินขีดจำกัด · ไม่เลื่อน checkpoint');
  // Each valid Order can be committed, but bad Orders remain in a persistent
  // local retry ledger. The caller saves pending+checkpoint in the same state
  // update; a crash before that update only causes a safe re-import of the page.
  if(options.autoEnrich&&options.tabId&&String((await accountForTab(options.tabId)).userid)!==String(account.userid))
    throw new Error('SESSION_BLOCK Shopee account changed before PAN import');
  let importResult={},cancelledResult={deleted:0};
  if(batch.length)importResult=await postBatch(hub,batch,{url:pageUrl,scanId,jobType:options.jobType||'sync'})||{};
  if(cancelledNos.length)cancelledResult=await postCancelled(hub,String(account.userid),cancelledNos)||{deleted:0};
  let autoDetail={ok:0,errors:0,lastError:''};
  if(options.autoEnrich&&options.tabId&&Array.isArray(importResult.detail_refresh_order_nos)&&importResult.detail_refresh_order_nos.length){
    const typeByOrder=new Map(raw.map(x=>{const n=normalizeOrder(x,account);return [String(n.orderNo||''),Number(x?.list_type||0)]}));
    const rows=importResult.detail_refresh_order_nos.map(no=>({order_no:String(no),list_type:typeByOrder.get(String(no))||0}));
    autoDetail=await autoEnrichOrders(hub,options.tabId,account,rows,detailMetas);
  }
  const panOrders=Number.isFinite(Number(importResult.pan_purchase_orders))?Number(importResult.pan_purchase_orders):(Number.isFinite(Number(cancelledResult.pan_purchase_orders))?Number(cancelledResult.pan_purchase_orders):null);
  return {orderCount,itemCount:batch.length,buyerDetailComplete,buyerDetailPreview,ignored,cancelled,dateUnknown,shopeeRecords:raw.length,
    newUnique,duplicateRecords,seenOrderNos:[...seen],pendingItemOrders,resolvedOrderNos,reasonCounts,
    insertedOrders:Number(importResult.inserted_orders||0),updatedOrders:Number(importResult.updated_orders||0),
    cancelledDeleted:Number(cancelledResult.deleted||0),panOrders,
    autoDetailUpdated:autoDetail.ok,autoDetailErrors:autoDetail.errors,lastAutoDetailError:autoDetail.lastError};
}
function syncCounterPatch(st,rr){
  const pending=mergePendingOrders(st.pendingItemOrders,rr.pendingItemOrders,rr.resolvedOrderNos);
  return {pendingItemOrders:pending,pendingItemCount:pending.length,pendingReasons:pendingReasonCounts(pending),
    partial:pending.length>0,orders:Number(st.orders||0)+rr.orderCount,items:Number(st.items||0)+rr.itemCount,buyerDetailComplete:Number(st.buyerDetailComplete||0)+Number(rr.buyerDetailComplete||0),buyerDetailPreview:Number(st.buyerDetailPreview||0)+Number(rr.buyerDetailPreview||0),ignored:Number(st.ignored||0)+rr.ignored,cancelled:Number(st.cancelled||0)+rr.cancelled,dateUnknown:Number(st.dateUnknown||0)+rr.dateUnknown,shopeeRecords:Number(st.shopeeRecords||0)+rr.shopeeRecords,shopeeUniqueOrders:Number(st.shopeeUniqueOrders||0)+rr.newUnique,duplicateRecords:Number(st.duplicateRecords||0)+rr.duplicateRecords,panInsertedOrders:Number(st.panInsertedOrders||0)+rr.insertedOrders,panUpdatedOrders:Number(st.panUpdatedOrders||0)+rr.updatedOrders,cancelledDeleted:Number(st.cancelledDeleted||0)+rr.cancelledDeleted,autoDetailUpdated:Number(st.autoDetailUpdated||0)+Number(rr.autoDetailUpdated||0),autoDetailErrors:Number(st.autoDetailErrors||0)+Number(rr.autoDetailErrors||0),lastAutoDetailError:rr.lastAutoDetailError||st.lastAutoDetailError||'',panOrders:rr.panOrders===null?Number(st.panOrders||0):rr.panOrders,syncSeenOrderNos:rr.seenOrderNos};
}

async function runSync(tabId,fresh){
  if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');
  runningJob='sync';
  try{
    const cfg=await chrome.storage.local.get('hubUrl'),hub=cfg.hubUrl||DEFAULT_HUB;
    const account=await accountForTab(tabId),aid=String(account.userid);
    const hubStatus=await hubJson(hub,`/api/status.php?account_id=${encodeURIComponent(aid)}`).catch(()=>null),initialPanOrders=Number(hubStatus?.account_purchase_orders??hubStatus?.purchase_orders??hubStatus?.orders??0)||0;
    const statusTypes=[3,7,8,12,4,9]; // type 4 is read only to delete cancelled rows; never imported.
    let st=await getState(aid);
    if(st.pendingItemCount>0&&st.pendingHub&&st.pendingHub!==hub)throw new Error('PAN URL เปลี่ยนขณะมีออเดอร์ค้างตรวจ · ต้องใช้ PAN เดิมหรือยืนยันล้าง checkpoint');
    const retainedPending=Array.isArray(st.pendingItemOrders)?st.pendingItemOrders:[];
    if(fresh)st={offset:0,orders:0,items:0,ignored:0,cancelled:0,dateUnknown:0,pages:0,done:false,paused:false,seenOffsets:[],scanId:crypto.randomUUID(),apiMode:'primary',statusIndex:0,statusOffset:0,statusSeenOffsets:[],shopeeRecords:0,shopeeUniqueOrders:0,duplicateRecords:0,panInsertedOrders:0,panOrders:initialPanOrders,syncSeenOrderNos:[],pendingItemOrders:retainedPending,pendingItemCount:retainedPending.length,pendingReasons:pendingReasonCounts(retainedPending),pendingHub:hub,partial:retainedPending.length>0,scanComplete:false};
    else if(st.panOrders===undefined)st.panOrders=initialPanOrders;
    if(!st.scanId)st.scanId=crypto.randomUUID();if(!st.apiMode)st.apiMode='primary';
    st=await setState(aid,{...st,pendingHub:hub,accountUsername:account.username||account.nickname||'',accountId:aid,running:true,paused:false,error:'',done:false,job:'sync',status:'starting'});
    const limit=20;let retries=0;
    while(true){
      st=await getState(aid);if(st.paused){await setState(aid,{running:false,status:'paused'});return;}
      if(String((await accountForTab(tabId)).userid)!==aid)throw new Error('บัญชี Shopee เปลี่ยนระหว่าง Full Sync · หยุดก่อนนำเข้าข้อมูลข้ามบัญชี');
      if(st.apiMode==='primary'){
        const offset=Number(st.offset||0);
        if((st.seenOffsets||[]).includes(offset)&&Number(st.pages||0)>0)throw new Error('ตรวจพบ pagination วนซ้ำใน primary endpoint');
        let page;try{page=await mainWorldPage(tabId,offset,limit)}catch(e){throw new Error('เรียก Shopee API ไม่สำเร็จ: '+e.message)}
        if(page.http===401||page.http===403)throw new Error(`Shopee HTTP ${page.http} · กรุณา reload หน้า การซื้อของฉัน แล้วลองใหม่`);
        if(page.http===429||page.http>=500){if(retries++<5){await setState(aid,{status:`retry HTTP ${page.http}`});await sleep(Math.min(15000,1500*Math.pow(2,retries)));continue;}throw new Error(`Shopee HTTP ${page.http} หลัง retry`);}retries=0;
        const apiError=Number(page?.json?.error||0);if(apiError===90309999)throw new Error(`Shopee anti-fraud 90309999 · shape=${JSON.stringify(responseShape(page.json))}`);if(apiError!==0)throw new Error(`Shopee API error ${apiError} · shape=${JSON.stringify(responseShape(page.json))}`);
        const parsed=pickDetailsInfo(page.json);
        if(!parsed.recognized){
          const shape=responseShape(page.json);
          if(primaryMetadataOnly(page.json)){
            const retryKey=`primary:${offset}`,retryCount=(st.primaryMetaRetryKey===retryKey?Number(st.primaryMetaRetryCount||0):0)+1;
            if(retryCount<3){st=await setState(aid,{primaryMetaRetryKey:retryKey,primaryMetaRetryCount:retryCount,primaryShape:shape,status:`primary metadata-only · retry ${retryCount}/2 · offset ${offset}`});await sleep(1200*retryCount);continue;}
            await setState(aid,{apiMode:'status',statusIndex:0,statusOffset:0,statusSeenOffsets:[],primaryMetaRetryKey:'',primaryMetaRetryCount:0,status:'primary metadata-only ซ้ำ · ใช้ status fallback โดยไม่เลื่อน checkpoint',primaryShape:shape,lastFormat:'primary-metadata-only'});continue;
          }
          if(Number(st.pages||0)===0){await setState(aid,{apiMode:'status',statusIndex:0,statusOffset:0,statusSeenOffsets:[],status:'primary ไม่มี order list · ใช้ status fallback',primaryShape:shape,lastFormat:'primary-unavailable'});continue;}
          throw new Error(`Shopee primary schema เปลี่ยนระหว่าง Sync · shape=${JSON.stringify(shape)}`);
        }
        if(st.primaryMetaRetryCount||st.primaryMetaRetryKey)st=await setState(aid,{primaryMetaRetryKey:'',primaryMetaRetryCount:0});
        const raw=parsed.records;
        if(raw.length===0&&Number(st.pages||0)===0){await setState(aid,{apiMode:'status',statusIndex:0,statusOffset:0,statusSeenOffsets:[],status:'primary คืน 0 record · ใช้ status fallback',lastFormat:parsed.format});continue;}
        const rr=await processSyncRecords(raw,account,hub,st.scanId,page.url,st.syncSeenOrderNos||[],{autoEnrich:true,tabId,jobType:'sync'});
        const next=nextOffset(page.json,offset,limit,raw.length),seen=[...(st.seenOffsets||[]).slice(-300),offset];
        st=await setState(aid,{offset:next,...syncCounterPatch(st,rr),pages:Number(st.pages||0)+1,lastPageCount:raw.length,lastFormat:parsed.format,seenOffsets:seen,status:`primary page ${Number(st.pages||0)+1} · offset ${offset}`});
        if(next===-1||raw.length===0){
          const unresolved=(st.pendingItemOrders||[]).length;
          if(unresolved){await setState(aid,{running:false,done:false,partial:true,scanComplete:true,
            status:partialSyncText(unresolved)});return;}
          if(Number(st.orders||0)>0){const rec=await hubJson(hub,'/api/reconcile.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({account_id:aid,scan_id:st.scanId})});if(rec?.ok===false)throw new Error(rec.error||'PAN reconcile ไม่สำเร็จ');}await setState(aid,{running:false,done:true,partial:false,scanComplete:true,status:'completed',apiMode:'primary'});return;}
        await sleep(650+Math.floor(Math.random()*550));continue;
      }

      const statusIndex=Number(st.statusIndex||0);
      if(statusIndex>=statusTypes.length){
        const unresolved=(st.pendingItemOrders||[]).length;
        if(unresolved){await setState(aid,{running:false,done:false,partial:true,scanComplete:true,
          status:partialSyncText(unresolved)});return;}
        if(Number(st.orders||0)>0){const rec=await hubJson(hub,'/api/reconcile.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({account_id:aid,scan_id:st.scanId})});if(rec?.ok===false)throw new Error(rec.error||'PAN reconcile ไม่สำเร็จ');}await setState(aid,{running:false,done:true,partial:false,scanComplete:true,status:'completed · status fallback',apiMode:'status'});return;}
      const listType=statusTypes[statusIndex],offset=Number(st.statusOffset||0),statusSeen=Array.isArray(st.statusSeenOffsets)?st.statusSeenOffsets:[];
      if(statusSeen.includes(`${listType}:${offset}`))throw new Error(`ตรวจพบ pagination วนซ้ำ · list_type=${listType} offset=${offset}`);
      let page;try{page=await mainWorldStatusPage(tabId,listType,offset,limit)}catch(e){throw new Error('เรียก Shopee status API ไม่สำเร็จ: '+e.message)}
      if(page.http===401||page.http===403)throw new Error(`Shopee HTTP ${page.http} · list_type=${listType}`);
      const apiError=Number(page?.json?.error||0);if(apiError===90309999)throw new Error(`Shopee anti-fraud 90309999 · status list_type=${listType} · shape=${JSON.stringify(responseShape(page.json))}`);
      if(apiError!==0){
        const shape=responseShape(page.json);
        if(canSkipOptionalStatusApiError(listType,apiError)){
          st=await setState(aid,{statusIndex:statusIndex+1,statusOffset:0,statusMetaRetryKey:'',statusMetaRetryCount:0,skippedStatusTypes:appendSkippedStatusTypes(st,listType),lastOptionalStatusError:{list_type:listType,error:apiError,shape},lastFormat:`status:${listType}:api-error-${apiError}-optional-skip`,status:`status type ${listType} ไม่รองรับในบัญชีนี้ · ข้ามเฉพาะหมวด non-purchase`});
          await sleep(350);continue;
        }
        throw new Error(`Shopee API error ${apiError} · list_type=${listType} · shape=${JSON.stringify(shape)}`);
      }
      const parsed=pickStatusDetailsInfo(page.json);
      if(!parsed.recognized){
        const shape=responseShape(page.json),meta=statusMetadataOnlyInfo(page.json);
        if(meta){
          const retryKey=`status:${listType}:${offset}`;
          if(meta.next_offset===-1){
            st=await setState(aid,{statusIndex:statusIndex+1,statusOffset:0,statusMetaRetryKey:'',statusMetaRetryCount:0,statusShape:shape,lastFormat:`status:${listType}:${meta.path}-metadata-terminal`,status:`status type ${listType} เสร็จ · terminal metadata`});
            await sleep(350);continue;
          }
          const retryCount=(st.statusMetaRetryKey===retryKey?Number(st.statusMetaRetryCount||0):0)+1;
          if(retryCount<3){st=await setState(aid,{statusMetaRetryKey:retryKey,statusMetaRetryCount:retryCount,statusShape:shape,status:`status type ${listType} metadata-only · retry ${retryCount}/2 · offset ${offset} · checkpoint ยังไม่เลื่อน`});await sleep(1200*retryCount);continue;}
          throw new Error(`Shopee status-list metadata-only ยังมี next_offset=${meta.next_offset} หลัง retry · list_type=${listType} · checkpoint ยังไม่เลื่อน · shape=${JSON.stringify(shape)}`);
        }
        throw new Error(`Shopee status-list schema ไม่ตรง · list_type=${listType} · shape=${JSON.stringify(shape)}`);
      }
      if(st.statusMetaRetryCount||st.statusMetaRetryKey)st=await setState(aid,{statusMetaRetryKey:'',statusMetaRetryCount:0});
      const raw=parsed.records.map(x=>(x&&x.list_type===undefined)?{...x,list_type:listType}:x),rr=await processSyncRecords(raw,account,hub,st.scanId,page.url,st.syncSeenOrderNos||[],{autoEnrich:true,tabId,jobType:'sync'}),next=nextOffset(page.json,offset,limit,raw.length),seen=[...statusSeen.slice(-500),`${listType}:${offset}`];
      const common={...syncCounterPatch(st,rr),pages:Number(st.pages||0)+1,lastPageCount:raw.length,lastFormat:`status:${listType}:${parsed.format}`,statusSeenOffsets:seen,apiMode:'status'};
      if(next===-1||raw.length===0)st=await setState(aid,{...common,statusIndex:statusIndex+1,statusOffset:0,status:`status type ${listType} เสร็จ`});
      else st=await setState(aid,{...common,statusIndex,statusOffset:next,status:`status type ${listType} · offset ${offset}`});
      await sleep(650+Math.floor(Math.random()*550));
    }
  }catch(e){try{const last=(await chrome.storage.local.get('lastAccountId')).lastAccountId||'';if(last)await setState(last,{running:false,error:String(e.message||e),status:'error'});}catch{}}
  finally{runningJob=null;}
}
async function runRepair(tabId,resume=false,all=false){
  if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');
  runningJob='repair';
  try{
    const cfg=await chrome.storage.local.get('hubUrl'),hub=cfg.hubUrl||DEFAULT_HUB,account=await accountForTab(tabId),aid=String(account.userid);
    let st=await setState(aid,{running:true,paused:false,error:'',done:false,job:'repair',repairDone:0,repairUpdated:0,repairErrors:0,status:all?'กำลังโหลดคิวเติมรายละเอียดทั้งหมด':'กำลังโหลดข้อมูลที่ยังขาด'});
    const queue=[];let offset=0,total=0;
    while(true){const q=await hubJson(hub,`/api/repair_queue.php?account_id=${encodeURIComponent(aid)}&include_legacy=0&limit=1000&offset=${offset}&all=${all?1:0}`),rows=q.orders||[];total=Number(q.total||0);queue.push(...rows);if(!q.has_more||!rows.length)break;offset+=rows.length;}
    await setState(aid,{repairTotal:queue.length,repairDone:0,status:queue.length?`เตรียมเติมรายละเอียด ${queue.length} Order`:'ไม่มี Order ที่ต้องเติมรายละเอียด'});
    for(let i=0;i<queue.length;i++){
      st=await getState(aid);if(st.paused){await setState(aid,{running:false,status:'paused detail enrichment'});return;}
      if(String((await accountForTab(tabId)).userid)!==aid)throw new Error('บัญชี Shopee เปลี่ยนระหว่างเติมรายละเอียด');
      const row=queue[i];try{await enrichOne(hub,tabId,account,row.order_no,row.list_type);st=await setState(aid,{repairDone:i+1,repairUpdated:Number(st.repairUpdated||0)+1,status:`เติมรายละเอียด ${i+1}/${queue.length}`});}
      catch(e){const msg=String(e.message||e);if(msg.startsWith('SESSION_BLOCK'))throw new Error(msg.replace(/^SESSION_BLOCK /,'')+' · หยุดเพื่อไม่ยิงซ้ำหลายร้อย Order');st=await setState(aid,{repairDone:i+1,repairErrors:Number(st.repairErrors||0)+1,lastRepairError:msg,status:`เติมรายละเอียด ${i+1}/${queue.length}`});}
      await sleep(300+Math.floor(Math.random()*250));
    }
    await setState(aid,{running:false,done:true,status:queue.length?'เติมรายละเอียดเสร็จ':'ไม่มีข้อมูลที่ต้องเติม'});
  }catch(e){try{const last=(await chrome.storage.local.get('lastAccountId')).lastAccountId||'';if(last)await setState(last,{running:false,error:String(e.message||e),status:'error'});}catch{}}
  finally{runningJob=null;}
}

async function runProductEnrichment(tabId){
  if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runningJob='product-enrich';
  try{const cfg=await chrome.storage.local.get('hubUrl'),hub=cfg.hubUrl||DEFAULT_HUB,account=await accountForTab(tabId),aid=String(account.userid);let done=0,errors=0;
    await setState(aid,{running:true,paused:false,done:false,error:'',job:'product-enrich',productEnrichDone:0,productEnrichErrors:0,status:'กำลังโหลดสินค้าที่ยังไม่มีหมวด'});
    let afterShop='',afterItem='';
    while(true){
      const cursor=afterShop&&afterItem?`&after_shop_id=${encodeURIComponent(afterShop)}&after_item_id=${encodeURIComponent(afterItem)}`:'';
      const q=await hubJson(hub,`/api/product_queue.php?account_id=${encodeURIComponent(aid)}&limit=100${cursor}`),rows=q.products||[];
      if(!rows.length)break;
      for(const row of rows){const st=await getState(aid);if(st.paused){await setState(aid,{running:false,status:'หยุดเติมหมวดสินค้า'});return;}if(String((await accountForTab(tabId)).userid)!==aid)throw new Error('บัญชี Shopee เปลี่ยนระหว่างเติมหมวดสินค้า');
        try{const r=await mainWorldProduct(tabId,row.shop_id,row.item_id);if(!r?.ok||!r?.json)throw new Error('Shopee product detail unavailable');const cat=extractCategoryInfo(r.json);if(!cat||(!cat.category_name&&!cat.category_id))throw new Error('Shopee product detail ไม่มี category ที่อ่านได้');await hubJson(hub,'/api/product_enrich.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({shop_id:String(row.shop_id),item_id:String(row.item_id),...cat,source:'shopee_product_detail'})});done++;await setState(aid,{productEnrichDone:done,productEnrichErrors:errors,status:`เติมหมวดสินค้า ${done} สำเร็จ / ${errors} ผิดพลาด`});}
        catch(e){errors++;await setState(aid,{productEnrichDone:done,productEnrichErrors:errors,lastProductEnrichError:String(e.message||e),status:`เติมหมวดสินค้า ${done} สำเร็จ / ${errors} ผิดพลาด`});}
        await sleep(350+Math.floor(Math.random()*250));}
      // Each key is visited once per run, even if Shopee denies category data.
      // Failed products stay pending for a manual retry, but cannot starve later pages.
      const last=q.next_cursor||{shop_id:rows.at(-1)?.shop_id,item_id:rows.at(-1)?.item_id};
      const nextShop=String(last?.shop_id||''),nextItem=String(last?.item_id||'');
      if(!nextShop||!nextItem||(nextShop===afterShop&&nextItem===afterItem))throw new Error('PAN product queue cursor did not advance');
      afterShop=nextShop;afterItem=nextItem;
      if(q.has_more===false||rows.length<100)break;
    }await setState(aid,{running:false,done:true,status:`เติมหมวดสินค้าเสร็จ · ${done} สำเร็จ · ${errors} ผิดพลาด`});
  }catch(e){try{const last=(await chrome.storage.local.get('lastAccountId')).lastAccountId||'';if(last)await setState(last,{running:false,error:String(e.message||e),status:'error'});}catch{}}finally{runningJob=null;}
}

// Only completed/cancelled histories stop early. Other statuses are checked in full.
function recentPageIsOld(raw,cutoff){
  return Boolean(cutoff&&raw.length&&raw.every(r=>{const d=extractBestListDate(r);return d&&d.date<cutoff;}));
}
function recentPrimaryInfo(json){
  const parsed=pickDetailsInfo(json);
  if(!parsed.recognized)return parsed;
  const original=getPath(json,parsed.format);
  // A nonempty but unfamiliar list must never be mistaken for end-of-history.
  if(!Array.isArray(original)||original.length!==parsed.records.length)return {recognized:false,records:[],format:parsed.format};
  return parsed;
}
async function runRecentSync(tabId,fresh=true){
  if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');
  runningJob='recent';let aid='';
  try{
    const cfg=await chrome.storage.local.get('hubUrl'),hub=(cfg.hubUrl||DEFAULT_HUB).replace(/\/$/,'');
    const account=await accountForTab(tabId);aid=String(account.userid);
    const hubStatus=await hubJson(hub,`/api/status.php?account_id=${encodeURIComponent(aid)}`).catch(()=>null),initialPanOrders=Number(hubStatus?.account_purchase_orders??hubStatus?.purchase_orders??hubStatus?.orders??0)||0;
    let st=await getState(aid);
    if(!fresh&&(st.job!=='recent'||st.recentHub!==hub))throw new Error('checkpoint ไม่ตรงกับงานล่าสุดหรือ PAN URL กรุณาเริ่มอัปเดตช่วงล่าสุดใหม่');
    if((fresh||st.done)&&st.pendingItemCount>0&&st.pendingHub&&st.pendingHub!==hub)throw new Error('PAN URL เปลี่ยนขณะมีออเดอร์ค้างตรวจ');
    if(fresh||st.done){
      const anchor=await hubJson(hub,`/api/sync_anchor.php?account_id=${encodeURIComponent(aid)}`);
      if(String(anchor.account_id)!==aid||!(anchor.cutoff_date===''||/^\d{4}-\d{2}-\d{2}$/.test(anchor.cutoff_date)))throw new Error('PAN ส่งข้อมูลจุดเริ่มต้นไม่ถูกต้อง');
      st={job:'recent',recentEndpoint:'primary',recentHub:hub,cutoff:anchor.cutoff_date,latestSavedDate:anchor.latest_order_date,recentIndex:0,recentOffset:0,recentOldPages:0,recentSeen:[],scanId:crypto.randomUUID(),pages:0,orders:0,items:0,ignored:0,cancelled:0,dateUnknown:0,shopeeRecords:0,shopeeUniqueOrders:0,duplicateRecords:0,panInsertedOrders:0,panUpdatedOrders:0,cancelledDeleted:0,panOrders:initialPanOrders,syncSeenOrderNos:[],pendingItemOrders:st.pendingItemOrders||[],pendingItemCount:(st.pendingItemOrders||[]).length,pendingReasons:pendingReasonCounts(st.pendingItemOrders||[]),pendingHub:hub,partial:false,scanComplete:false};
    }
    st=await setState(aid,{...st,pendingHub:hub,job:'recent',apiMode:'recent',accountUsername:account.username||account.nickname||'',running:true,paused:false,done:false,error:'',status:st.cutoff?`อัปเดตตั้งแต่ ${st.cutoff} · รวมช่วงทับซ้อนและรายการค้าง`:'ไม่มีวันที่อ้างอิงที่ปลอดภัย · ตรวจรายการทั้งหมดครั้งนี้'});
    const types=[7,8,9,12,3,4],limit=20;let retries=0;
    while(Number(st.recentIndex||0)<types.length){
      st=await getState(aid);if(st.paused){await setState(aid,{running:false,status:'พักอัปเดตช่วงล่าสุด'});return;}
      const primary=st.recentEndpoint==='primary';
      const index=Number(st.recentIndex||0),type=types[index],offset=Number(st.recentOffset||0),key=`${primary?'primary':type}:${offset}`;
      if((st.recentSeen||[]).includes(key))throw new Error('ตรวจพบ pagination วนซ้ำในการอัปเดตช่วงล่าสุด');
      // Confirm identity for every page: switching accounts mid-job must not mix order history.
      if(String((await accountForTab(tabId)).userid)!==aid)throw new Error('บัญชี Shopee เปลี่ยนระหว่างทำงาน กรุณากลับบัญชีเดิมแล้วทำต่อ');
      const page=primary?await mainWorldPage(tabId,offset,limit):await mainWorldStatusPage(tabId,type,offset,limit);
      if(page.http===401||page.http===403)throw new Error(`Shopee HTTP ${page.http}`);
      if(page.http===429||page.http>=500){if(retries++<5){await setState(aid,{status:`retry HTTP ${page.http}`});await sleep(Math.min(15000,1500*Math.pow(2,retries)));continue;}throw new Error(`Shopee HTTP ${page.http} หลัง retry`);}retries=0;
      const code=Number(page.json?.error||0);if(code===90309999)throw new Error('Shopee anti-fraud 90309999');
      if(!page.ok||code){
        if(!primary&&page.ok&&canSkipOptionalStatusApiError(type,code)){
          const shape=responseShape(page.json);
          st=await setState(aid,{recentIndex:index+1,recentOffset:0,recentOldPages:0,recentSeen:[],recentStatusMetaRetryKey:'',recentStatusMetaRetryCount:0,skippedStatusTypes:appendSkippedStatusTypes(st,type),lastOptionalStatusError:{list_type:type,error:code,shape},status:`อัปเดตช่วงล่าสุด · ประเภท ${type} ไม่รองรับในบัญชีนี้ · ข้ามเฉพาะหมวด non-purchase`});
          continue;
        }
        throw new Error(`Shopee HTTP ${page.http} · API error ${code}`);
      }
      const parsed=primary?recentPrimaryInfo(page.json):pickStatusDetailsInfo(page.json);
      if(!parsed.recognized){
        const shape=responseShape(page.json);
        if(primary&&primaryMetadataOnly(page.json)){
          const retryKey=`primary:${offset}`,retryCount=(st.recentMetaRetryKey===retryKey?Number(st.recentMetaRetryCount||0):0)+1;
          if(retryCount<3){st=await setState(aid,{recentMetaRetryKey:retryKey,recentMetaRetryCount:retryCount,primaryShape:shape,status:`Shopee primary metadata-only · retry ${retryCount}/2 · offset ${offset} · checkpoint ยังไม่เลื่อน`});await sleep(1200*retryCount);continue;}
          st=await setState(aid,{recentEndpoint:'status',recentIndex:0,recentOffset:0,recentOldPages:0,recentSeen:[],recentMetaRetryKey:'',recentMetaRetryCount:0,recentFallbackFromPrimary:true,primaryShape:shape,status:'primary metadata-only ซ้ำ · สลับ status-list fallback จาก offset 0 · scan เดิม'});
          continue;
        }
        if(!primary){
          const meta=statusMetadataOnlyInfo(page.json),retryKey=`recent-status:${type}:${offset}`;
          if(meta){
            if(meta.next_offset===-1){
              st=await setState(aid,{recentIndex:index+1,recentOffset:0,recentOldPages:0,recentSeen:[],recentStatusMetaRetryKey:'',recentStatusMetaRetryCount:0,statusShape:shape,status:`อัปเดตช่วงล่าสุด · ประเภท ${type} เสร็จ · terminal metadata`});
              continue;
            }
            const retryCount=(st.recentStatusMetaRetryKey===retryKey?Number(st.recentStatusMetaRetryCount||0):0)+1;
            if(retryCount<3){st=await setState(aid,{recentStatusMetaRetryKey:retryKey,recentStatusMetaRetryCount:retryCount,statusShape:shape,status:`status type ${type} metadata-only · retry ${retryCount}/2 · offset ${offset} · checkpoint ยังไม่เลื่อน`});await sleep(1200*retryCount);continue;}
            throw new Error(`Shopee status-list metadata-only ยังมี next_offset=${meta.next_offset} หลัง retry · type=${type} · checkpoint ยังไม่เลื่อน · shape=${JSON.stringify(shape)}`);
          }
          if(st.recentFallbackFromPrimary){await setState(aid,{statusShape:shape});throw new Error(`Shopee status fallback schema ไม่ตรง · checkpoint ยังไม่เลื่อน · shape=${JSON.stringify(shape)}`);}
          // Migrate 2.4.1 checkpoints to the endpoint used by the original full sync.
          st=await setState(aid,{recentEndpoint:'primary',recentIndex:0,recentOffset:0,recentOldPages:0,recentSeen:[],statusShape:shape,status:'status-list ไม่รองรับ · เปลี่ยนเป็นรายการรวมช่วงล่าสุด'});
          continue;
        }
        await setState(aid,{primaryShape:shape});
        throw new Error(`Shopee รายการรวม schema ไม่ตรง · ไม่เลื่อน checkpoint · shape=${JSON.stringify(shape)}`);
      }
      if(st.recentMetaRetryCount||st.recentMetaRetryKey)st=await setState(aid,{recentMetaRetryKey:'',recentMetaRetryCount:0});
      const raw=primary?parsed.records:parsed.records.map(r=>r.list_type===undefined?{...r,list_type:type}:r);
      if(primary&&raw.some(r=>![3,4,7,8,9,12].includes(Number(r.list_type))))throw new Error('Shopee ส่งประเภทคำสั่งซื้อที่ยังไม่รองรับ · ไม่เลื่อน checkpoint');
      const counts=await processSyncRecords(raw,account,hub,st.scanId,page.url,st.syncSeenOrderNos||[],{autoEnrich:true,tabId,jobType:'recent'});
      const next=nextOffset(page.json,offset,limit,raw.length);
      const oldPages=(primary||[3,4].includes(type))&&recentPageIsOld(raw,st.cutoff)?Number(st.recentOldPages||0)+1:0;
      const finished=next===-1||!raw.length||oldPages>=2;
      if(!finished&&(!Number.isFinite(next)||next<=offset))throw new Error('Shopee ส่ง offset ถอยหลัง · ไม่เลื่อน checkpoint');
      st=await setState(aid,{recentIndex:finished?(primary?types.length:index+1):index,recentOffset:finished?0:next,recentOldPages:finished?0:oldPages,recentSeen:finished?[]:[...(st.recentSeen||[]),key],...syncCounterPatch(st,counts),pages:Number(st.pages||0)+1,status:`อัปเดตช่วงล่าสุด · ${primary?'รายการรวม':'ประเภท '+type} · หน้า ${Number(st.pages||0)+1}`});
      if(st.recentIndex<types.length)await sleep(650+Math.floor(Math.random()*550));
    }
    // Deliberately NO reconcile: older unvisited rows must retain their visibility.
    const pending=(st.pendingItemOrders||[]).length;
    if(pending)await setState(aid,{running:false,done:false,partial:true,scanComplete:true,status:partialSyncText(pending)});
    else await setState(aid,{running:false,done:true,partial:false,scanComplete:true,status:'อัปเดตช่วงล่าสุดเสร็จ · เก็บข้อมูลเก่าครบตามเดิม'});
  }catch(e){if(aid)await setState(aid,{running:false,error:String(e.message||e),status:'error'});else throw e;}
  finally{runningJob=null;}
}
/** Re-attempt only the quarantined Orders against the authenticated Buyer Detail.
 * This does NOT replay unrelated orders or change any scan pagination cursor.
 */
async function retryPendingItemOrders(tabId){
  if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');
  runningJob='item_retry';let aid='';
  try{
    const cfg=await chrome.storage.local.get('hubUrl'),hub=cfg.hubUrl||DEFAULT_HUB;
    const account=await accountForTab(tabId);aid=String(account.userid);
    let st=await getState(aid);
    if(st.pendingHub&&st.pendingHub!==hub)throw new Error('PAN URL ไม่ตรงกับรายการที่ค้างตรวจสินค้า · ไม่บันทึกข้ามฐาน');
    const pending=Array.isArray(st.pendingItemOrders)?st.pendingItemOrders:[];
    if(!pending.length){await setState(aid,{running:false,done:Boolean(st.scanComplete),partial:false,pendingItemCount:0,status:'ไม่มีออเดอร์ที่ค้างตรวจสินค้า'});return;}
    await setState(aid,{job:'item_retry',running:true,done:false,paused:false,error:'',partial:true,
      pendingRetryDone:0,pendingRetryTotal:pending.length,status:`ตรวจ Buyer Detail อีกครั้ง ${pending.length} Order`});
    for(let i=0;i<pending.length;i++){
      const row=pending[i];st=await getState(aid);
      if(st.paused){await setState(aid,{running:false,status:'หยุดตรวจรายการค้างชั่วคราว'});return;}
      if(String((await accountForTab(tabId)).userid)!==aid)throw new Error('SESSION_BLOCK Shopee account switched during pending retry');
      try{
        const candidate=await resolveBuyerItemSnapshot(tabId,minimalOrderForPending(row),account);
        if(candidate.status==='complete'&&candidate.normalized?.items?.length){
          if(String(candidate.normalized.orderNo)!==String(row.orderNo))throw new Error('SESSION_BLOCK Buyer detail identity mismatch');
          await postBatch(hub,candidate.normalized.items,{url:'buyer-detail-retry',jobType:'buyer_detail_recheck',scanId:''});
          const current=await getState(aid);
          const rest=mergePendingOrders(current.pendingItemOrders,[],[row.orderNo]);
          await setState(aid,{pendingItemOrders:rest,pendingItemCount:rest.length,pendingReasons:pendingReasonCounts(rest),
            pendingRetryDone:i+1,status:`ตรวจรายการค้าง ${i+1}/${pending.length} · ยืนยันและบันทึกแล้ว`});
        }else{
          const current=await getState(aid),reason=candidate.reason||'detail_unavailable';
          const rest=(current.pendingItemOrders||[]).map(x=>x.orderNo===row.orderNo?{...x,reason}:x);
          await setState(aid,{pendingItemOrders:rest,pendingItemCount:rest.length,pendingReasons:pendingReasonCounts(rest),
            pendingRetryDone:i+1,status:`ตรวจรายการค้าง ${i+1}/${pending.length} · ยังไม่มีสินค้าที่เชื่อถือได้`});
        }
      }catch(err){
        const msg=String(err?.message||err);
        if(msg.startsWith('SESSION_BLOCK')||/HTTP 401|HTTP 403|HTTP 429/.test(msg))throw err;
        const curr=await getState(aid);
        const rest=(curr.pendingItemOrders||[]).map(x=>x.orderNo===row.orderNo?{...x,reason:'detail_retry_failed'}:x);
        await setState(aid,{pendingItemOrders:rest,pendingItemCount:rest.length,pendingReasons:pendingReasonCounts(rest),
          pendingRetryDone:i+1,status:`ตรวจรายการค้าง ${i+1}/${pending.length} · ยังต้องตรวจใหม่`});
      }
      await sleep(220);
    }
    st=await getState(aid);const left=(st.pendingItemOrders||[]).length;
    await setState(aid,{running:false,partial:left>0,done:left===0&&Boolean(st.scanComplete),
      status:left?partialSyncText(left):'ตรวจรายการค้างครบแล้ว · ดูผลใน PAN'});
  }catch(e){if(aid)await setState(aid,{running:false,error:String(e?.message||e),status:'error'});else throw e;}
  finally{runningJob=null;}
}

/** Recheck one order without changing Full/Recent Sync checkpoints or other orders. */
async function findSingleOrderInShopee(tabId,target,account,onPage=async()=>{}){
  const identity=record=>txt(record?.info_card?.order_id??record?.info_card?.order_sn??record?.order_id??record?.order_sn??'');
  const detail=await mainWorldDetail(tabId,target);
  if(detail?.http===401||detail?.http===403||Number(detail?.json?.error||0)===90309999)
    throw new Error('Shopee ไม่อนุญาต Order Detail / ต้องยืนยันเซสชัน');
  const data=detail?.json?.data;
  if(detail?.ok&&Number(detail?.json?.error||0)===0&&data){
    for(const body of [data,data?.order_list_detail,data?.order_detail,data?.order_info]){
      const candidate=orderRecordFromEntry(body);
      if(candidate?.info_card?.order_list_cards&&[3,7,8,9,12].includes(Number(candidate?.list_type))){
        if(identity(candidate)!==target)throw new Error('Shopee Order Detail identity mismatch; no write');
        return {record:candidate,url:detail.url,source:'detail'};
      }
    }
  }
  // Some Buyer detail responses contain only metadata; walk the existing
  // authenticated order-list endpoint, never scrape unrelated site content.
  const limit=20,maxPages=320;let visited=0;
  const contexts=[{kind:'primary',type:null},...[3,7,8,9,12].map(type=>({kind:'status',type}))];
  for(const context of contexts){
    let offset=0;const seen=new Set();
    while(visited<maxPages){
      if(seen.has(offset))throw new Error('Shopee pagination loop during single-order check');
      seen.add(offset);visited++;
      if(String((await accountForTab(tabId)).userid)!==String(account.userid))
        throw new Error('Shopee account switched during single-order check; no write');
      await onPage(visited,context.kind,context.type);
      const page=context.kind==='primary'?await mainWorldPage(tabId,offset,limit):await mainWorldStatusPage(tabId,context.type,offset,limit);
      const error=Number(page?.json?.error||0);
      if([401,403,429].includes(page?.http)||error===90309999)throw new Error('Shopee session or rate limit blocked targeted order check');
      if(error||!page?.ok){
        if(context.kind==='status'&&canSkipOptionalStatusApiError(context.type,error))break;
        if(context.kind==='primary'&&visited===1)break; // unsupported primary: status fallback
        throw new Error(`Shopee order-list error ${error||page?.http||0}, no data changed`);
      }
      const parsed=context.kind==='primary'?pickDetailsInfo(page.json):pickStatusDetailsInfo(page.json);
      if(!parsed.recognized){if(context.kind==='primary'&&offset===0)break;throw new Error('Shopee order list schema unknown, no data changed');}
      const selected=parsed.records.find(rec=>identity(rec)===target);
      if(selected)return {record:selected.list_type===undefined&&context.type?{...selected,list_type:context.type}:selected,url:page.url,source:context.kind};
      const next=nextOffset(page.json,offset,limit,parsed.rawCount);
      if(next===-1||parsed.rawCount===0)break;
      if(!Number.isSafeInteger(next)||next<=offset)throw new Error('Shopee page cursor invalid; no data changed');
      offset=next;
      await sleep(170);
    }
  }
  throw new Error(visited>=maxPages?'Reached read-only search limit without finding order; no PAN data changed':'Order not found in available Shopee Buyer API views; no PAN data changed');
}
/** One-order API audit without asking the user to type an expected quantity.
 * Never promotes condensed list items to an authoritative detail snapshot.
 */
async function refreshSingleOrder(tabId,orderNo){
  const target=txt(orderNo).trim();
  if(!/^[0-9A-Za-z-]{8,64}$/.test(target))throw new Error('ระบุเลข Order ให้ถูกต้อง');
  if(runningJob)throw new Error('มีงาน Sync/Repair กำลังทำอยู่');
  runningJob='single-order';let aid='';
  try{
    const account=await accountForTab(tabId);aid=String(account.userid);
    const publish=async patch=>setState(aid,{quantityAudit:{orderNo:target,...patch}});
    await publish({status:'scanning',message:'กำลังตรวจรายการสินค้าจาก Shopee Buyer Detail; ยังไม่แก้ข้อมูล PAN'});
    const found=await findSingleOrderInShopee(tabId,target,account,async count=>{
      if(count===1||count%10===0)await publish({status:'scanning',message:`กำลังค้นหา Shopee ${count} หน้า (ยังไม่แก้ข้อมูล)`});
    });
    const detail=await resolveBuyerItemSnapshot(tabId,found.record,account);
    if(detail.status!=='complete'){
      await publish({status:'waiting_detail',message:detail.status==='error'
        ?`ข้อมูลสินค้า Buyer Detail ขัดแย้ง (${detail.reason}) — ไม่แก้ PAN`
        :'Shopee Buyer Detail ยังไม่ส่งรายการสินค้าครบที่ตรวจสอบได้ — ไม่แก้ PAN หรือเดาจำนวน'});
      return;
    }
    const normalized=detail.normalized;
    if(normalized.orderNo!==target||!normalized.items.length)throw new Error('Order ID mismatch or item rows empty; no write');
    if(String((await accountForTab(tabId)).userid)!==aid)throw new Error('SESSION_BLOCK account switched; no write');
    const cfg=await chrome.storage.local.get('hubUrl');
    const hub=(cfg.hubUrl||DEFAULT_HUB).replace(/\/$/,'');
    const result=await postBatch(hub,normalized.items,{url:found.url,scanId:crypto.randomUUID(),jobType:'buyer_detail_recheck'});
    await publish({status:'updated',actual:normalized.itemQuantity,lines:normalized.items.length,
      message:`อัปเดตจาก Shopee Buyer Detail แล้ว · ${normalized.items.length} บรรทัด · ${normalized.itemQuantity} ชิ้น (ไม่ต้องกรอกจำนวนเอง)`,
      updated:Number(result.updated_orders||0)});
  }catch(e){
    if(aid)await setState(aid,{quantityAudit:{orderNo:target,status:'error',message:String(e.message||e)}});
    else throw e;
  }finally{runningJob=null;}
}

function reportRecentError(e){chrome.runtime.sendMessage({type:'SYNC_ERROR',error:String(e.message||e)}).catch(()=>{});}
chrome.runtime.onMessage.addListener((msg,sender,sendResponse)=>{(async()=>{
  if(msg?.type==='START_SYNC'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runSync(msg.tabId,true).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='START_RECENT'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runRecentSync(msg.tabId,true).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='RESUME_SYNC'){const a=await accountForTab(msg.tabId);const st=await getState(String(a.userid));if((st.scanComplete&&st.partial)||st.job==='item_retry')retryPendingItemOrders(msg.tabId).catch(reportRecentError);else if(st.job==='recent')runRecentSync(msg.tabId,false).catch(reportRecentError);else if(st.job==='repair'&&!st.done)runRepair(msg.tabId,true);else runSync(msg.tabId,false);sendResponse({ok:true});}
  else if(msg?.type==='RETRY_ITEM_PENDING'){retryPendingItemOrders(msg.tabId).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='START_REPAIR'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runRepair(msg.tabId,false,false).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='START_REPAIR_ALL'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runRepair(msg.tabId,false,true).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='REFRESH_SINGLE_ORDER'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');refreshSingleOrder(msg.tabId,msg.orderNo).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='START_PRODUCT_ENRICH'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runProductEnrichment(msg.tabId).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='PAUSE_SYNC'){const id=(await chrome.storage.local.get('lastAccountId')).lastAccountId||'';if(id)await setState(id,{paused:true,status:'pausing'});sendResponse({ok:true});}
  else if(msg?.type==='RESET_SYNC'){const id=String(msg.accountId||((await chrome.storage.local.get('lastAccountId')).lastAccountId||''));if(id){const all=await getAllStates(),prev=all[id]||{},pending=prev.pendingItemOrders||[];all[id]={pendingItemOrders:pending,pendingItemCount:pending.length,pendingReasons:pendingReasonCounts(pending),pendingHub:prev.pendingHub||'',partial:pending.length>0,done:false,scanComplete:false,status:pending.length?'รีเซ็ตจุดสแกนแล้ว · คิวสินค้าไม่ครบยังอยู่':'รีเซ็ตจุดสแกนแล้ว'};await chrome.storage.local.set({syncStates:all});}sendResponse({ok:true});}
  else if(msg?.type==='GET_STATE'){const id=String(msg.accountId||((await chrome.storage.local.get('lastAccountId')).lastAccountId||''));sendResponse({ok:true,state:await getState(id)});}
  else if(msg?.type==='GET_ACCOUNT'){try{const a=await accountForTab(msg.tabId);sendResponse({ok:true,account:a,state:await getState(String(a.userid))});}catch(e){sendResponse({ok:false,error:String(e.message||e)});}}
})().catch(e=>sendResponse({ok:false,error:String(e.message||e)}));return true;});
