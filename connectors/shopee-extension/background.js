const DEFAULT_HUB='https://pan.itoom.work';
const VERSION='2.4.13';
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
function normalizeOrder(raw,account,dateOverride=null,detail={}){const d=raw||{},info=d?.info_card||{},cards=Array.isArray(info?.order_list_cards)?info.order_list_cards:[];if(!cards.length)return {orderNo:'',items:[],ignoredReason:'missing_order_cards'};const identity=extractOrderIdentity(d,info,cards);if(!identity)return {orderNo:'',items:[],ignoredReason:'missing_order_identity'};const listType=Number(d?.list_type);if(![3,4,7,8,9,12].includes(listType))return {orderNo:identity.id,items:[],ignoredReason:'unknown_list_type'};if(listType===4)return {orderNo:identity.id,items:[],ignoredReason:'cancelled_order'};const dt=dateOverride||extractBestListDate(d);const orderDate=dt?.date||(dt?.epoch?dateOnly(dt.epoch):'');const createdAt=dt?.datetime||(dt?.epoch?formatBangkok(dt.epoch):'');const shop=cards[0]?.shop_info||{},shopId=shop?.shop_id??shop?.shopid??cards[0]?.shop_id??d?.shop_id??null,shopName=txt(shop?.shop_name||shop?.username||d?.shop_name||'');if(!shopName)return {orderNo:identity.id,items:[],ignoredReason:'missing_shop'};let flat=[];for(const c of cards){const groups=c?.product_info?.item_groups;if(Array.isArray(groups))for(const g of groups)if(Array.isArray(g?.items))flat.push(...g.items);}if(!flat.length)return {orderNo:identity.id,items:[],ignoredReason:'missing_items'};
  const prepared=[];let rawSubtotal=0;for(const card of flat){if(Number(card?.status)===3)continue;const itemId=card?.item_id??card?.itemid??null,modelId=card?.model_id??card?.modelid??card?.variation_id??0,name=txt(card?.name||card?.item_name||'').trim(),qty=Math.max(1,Number(card?.amount??card?.quantity??1)||1),sell=num(card?.item_price??card?.price??0);if(!name||sell<=0)continue;const original=num(card?.original_price??card?.model_original_price??card?.price_before_discount??card?.item_price??0),line=sell*qty;rawSubtotal+=line;prepared.push({card,itemId,modelId,name,qty,sell,original,line});}if(!prepared.length)return {orderNo:identity.id,items:[],ignoredReason:'missing_valid_items'};
  const finalTotal=num(info?.final_total??info?.total_payable??info?.subtotal??0);let shippingCandidate=null;for(const k of ['actual_shipping_fee','buyer_shipping_fee']){const f=deepFind(info,[k],4);if(f){shippingCandidate=num(f.value);break;}}const shippingFee=shippingCandidate==null?0:Math.max(0,shippingCandidate);let merchandisePaid=null,pricingMethod='order_total_only_unallocated';if(finalTotal>0&&shippingCandidate!=null&&finalTotal>=shippingFee){const candidate=finalTotal-shippingFee;if(candidate>=0&&candidate<=rawSubtotal*1.05){merchandisePaid=Math.min(rawSubtotal,candidate);pricingMethod='final_total_minus_shipping_proportional';}}else if(finalTotal>0&&finalTotal<=rawSubtotal){merchandisePaid=finalTotal;pricingMethod='final_total_proportional';}const ratio=(merchandisePaid!=null&&rawSubtotal>0)?merchandisePaid/rawSubtotal:1,discountTotal=merchandisePaid!=null?Math.max(0,rawSubtotal-merchandisePaid):0,status=statusFromListType(listType),purchaseState=[3,7,8].includes(listType)?'purchase':'non_purchase';
  const out=[];for(const p of prepared){const actualLine=p.line*ratio,actualUnit=actualLine/p.qty,allocatedDiscount=Math.max(0,p.line-actualLine),card=p.card,variant=txt(card?.model_name||card?.variation||card?.variation_name||''),img=card?.image??card?.image_url??card?.image_info??'',image=typeof img==='object'?(img?.image_url||img?.image_id||''):img,key=`shopee:${shopId||0}:${p.itemId||p.name}:${p.modelId||0}`;const categoryId=txt(card?.category_id??card?.catid??card?.category?.category_id??card?.category?.catid??'');const categoryName=txt(card?.category_name??card?.category?.name??card?.category?.display_name??'');const categoryPath=txt(card?.category_path??card?.category?.path??'');const familyName=normalizeFamilyName(p.name);out.push({platform:'shopee_th',order_no:identity.id,order_date:orderDate,order_created_at:createdAt,shop_name:shopName,product_key:key,product_name:p.name,variant_name:variant,image_url:imageUrl(image),product_url:productUrl(shopId,p.itemId),quantity:p.qty,original_price:p.original,purchase_price:p.sell,net_unit_price:actualUnit,actual_unit_price:actualUnit,actual_line_total:actualLine,allocated_discount:allocatedDiscount,total_paid:Math.max(0,finalTotal),raw_subtotal:rawSubtotal,subtotal:rawSubtotal,merchandise_paid:merchandisePaid==null?0:merchandisePaid,shipping_fee:shippingFee,voucher_discount:0,coins_discount:0,platform_discount:0,discount_total:discountTotal,pricing_method:pricingMethod,order_status:status,list_type:listType,purchase_state:purchaseState,validation_state:'verified_v200',source_account_id:txt(account?.userid||''),source_account_username:txt(account?.username||account?.nickname||''),date_source:dt?.path||'unknown',identity_source:identity.source,marketplace_shop_id:txt(shopId||''),marketplace_item_id:txt(p.itemId||''),marketplace_model_id:txt(p.modelId||''),marketplace_category_id:categoryId,marketplace_category_name:categoryName,marketplace_category_path:categoryPath,category_source:categoryName||categoryId?'order_list':'',category_updated_at:categoryName||categoryId?formatBangkok(Math.floor(Date.now()/1000)):'',product_family_key:familyName?`name:${familyName}`:key,product_family_name:p.name,...detail,raw_json:JSON.stringify({order_id:identity.id,list_type:listType,order_status:status,date_source:dt?.path||'unknown',shop_id:shopId,item_id:p.itemId,model_id:p.modelId,category_id:categoryId,category_name:categoryName,item_price:p.sell,quantity:p.qty,final_total:finalTotal,pricing_method:pricingMethod,source_account_id:txt(account?.userid||'')})});}return {orderNo:identity.id,items:out,ignoredReason:''};}

async function getAllStates(){return (await chrome.storage.local.get('syncStates')).syncStates||{};}
async function getState(accountId=''){const all=await getAllStates();return accountId?all[String(accountId)]||{}:(await chrome.storage.local.get('lastAccountId')).lastAccountId?all[String((await chrome.storage.local.get('lastAccountId')).lastAccountId)]||{}:{};}
async function setState(accountId,patch){const all=await getAllStates(),key=String(accountId),next={...(all[key]||{}),...patch,accountId:key,updatedAt:Date.now()};all[key]=next;await chrome.storage.local.set({syncStates:all,lastAccountId:key});chrome.runtime.sendMessage({type:'SYNC_PROGRESS',state:next}).catch(()=>{});return next;}
async function hubJson(hub,path,opts={}){const cfg=await chrome.storage.local.get('apiKey');opts={...opts,headers:{...(opts.headers||{}),'X-PAN-Key':cfg.apiKey||''}};const r=await fetch(hub.replace(/\/$/,'')+path,opts);const t=await r.text();let j;try{j=JSON.parse(t)}catch{throw new Error(`PAN HTTP ${r.status}: ${t.slice(0,180)}`)}if(!r.ok||j.ok===false)throw new Error(j.error||`PAN HTTP ${r.status}`);return j;}
async function postBatch(hub,items,meta){if(!items.length)return {};return hubJson(hub,'/api/import.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({source:'shopee_extension_v2',source_url:meta.url,job_type:meta.jobType||'sync',scan_id:meta.scanId,items})});}
async function enrichOne(hub,tabId,account,orderNo,listType=0){const r=await mainWorldDetail(tabId,orderNo),apiError=Number(r?.json?.error||0);if(r?.http===401||r?.http===403)throw new Error(`SESSION_BLOCK HTTP ${r.http}`);if(apiError===90309999)throw new Error('SESSION_BLOCK Shopee anti-fraud 90309999');if(apiError!==0)throw new Error(`Shopee API error ${apiError}`);if(!r?.ok||!r?.json?.data)throw new Error(`HTTP ${r?.http||0} / detail data empty`);const meta=detailMeta(r.json);return hubJson(hub,'/api/enrich.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({order_no:String(orderNo),list_type:Number(listType||0),source_account_id:String(account.userid),source_account_username:account.username||account.nickname||'',order_status:statusFromListType(listType),...meta})});}
async function autoEnrichOrders(hub,tabId,account,rows){let ok=0,errors=0,lastError='';for(const row of rows.slice(0,30)){try{if(String((await accountForTab(tabId)).userid)!==String(account.userid))throw new Error('SESSION_BLOCK บัญชี Shopee เปลี่ยนระหว่างเติมรายละเอียด');await enrichOne(hub,tabId,account,row.order_no,row.list_type);ok++;}catch(e){lastError=String(e.message||e);errors++;if(lastError.startsWith('SESSION_BLOCK'))throw new Error(lastError);}await sleep(260+Math.floor(Math.random()*180));}return {ok,errors,lastError};}
async function postCancelled(hub,accountId,orderNos){if(!orderNos.length)return {deleted:0};return hubJson(hub,'/api/cancelled.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({account_id:String(accountId),order_nos:[...new Set(orderNos.map(String))]})});}
async function accountForTab(tabId){const ai=await mainWorldAccount(tabId);if(!ai?.ok||!ai?.account?.userid)throw new Error(`ยืนยัน Shopee User ID ไม่สำเร็จ · HTTP ${ai?.http??0} · error ${ai?.json_error??''}`);return ai.account;}
async function resolveDetail(tabId,orderId){const r=await mainWorldDetail(tabId,orderId);if(r?.http===401||r?.http===403)throw new Error(`Shopee HTTP ${r.http} Order Detail`);if(Number(r?.json?.error||0)===90309999)throw new Error('Shopee anti-fraud 90309999 ที่ Order Detail');if(!r?.ok||!r?.json?.data)return null;return detailMeta(r.json);}

let runningJob=null;
async function processSyncRecords(raw,account,hub,scanId,pageUrl,seenOrderNos=[],options={}){
  const batch=[],cancelledNos=[];let orderCount=0,ignored=0,cancelled=0,dateUnknown=0,newUnique=0,duplicateRecords=0;const reasonCounts={};
  const seen=new Set((Array.isArray(seenOrderNos)?seenOrderNos:[]).map(String));
  for(const r of raw){
    const n=normalizeOrder(r,account);
    if(n.orderNo){const k=String(n.orderNo);if(seen.has(k))duplicateRecords++;else{seen.add(k);newUnique++;}}
    if(n.ignoredReason==='cancelled_order'){
      cancelled++;ignored++;if(n.orderNo)cancelledNos.push(n.orderNo);
      reasonCounts.cancelled_order=(reasonCounts.cancelled_order||0)+1;continue;
    }
    if(!extractBestListDate(r))dateUnknown++;
    if(n.items.length){orderCount++;batch.push(...n.items);}
    else{ignored++;const reason=n.ignoredReason||'unknown';reasonCounts[reason]=(reasonCounts[reason]||0)+1;}
  }
  const structural=['missing_order_cards','missing_order_identity','missing_shop','missing_items','missing_valid_items'].reduce((s,k)=>s+(reasonCounts[k]||0),0);
  if(structural>0)throw new Error(`Shopee schema บาง Order ไม่ตรงกับ Normalizer 2.4.13 · หยุดก่อนเลื่อน checkpoint · structural=${structural}/${raw.length} · reasons=${JSON.stringify(reasonCounts)} · sample=${JSON.stringify(raw.find(x=>{const n=normalizeOrder(x,account);return ['missing_order_cards','missing_order_identity','missing_shop','missing_items','missing_valid_items'].includes(n.ignoredReason)})||raw[0]).slice(0,6000)}`);
  let cancelledResult={deleted:0},importResult={};
  if(cancelledNos.length)cancelledResult=await postCancelled(hub,String(account.userid),cancelledNos)||{deleted:0};
  if(batch.length)importResult=await postBatch(hub,batch,{url:pageUrl,scanId,jobType:options.jobType||'sync'})||{};
  let autoDetail={ok:0,errors:0,lastError:''};if(options.autoEnrich&&options.tabId&&Array.isArray(importResult.detail_refresh_order_nos)&&importResult.detail_refresh_order_nos.length){const typeByOrder=new Map(raw.map(x=>{const n=normalizeOrder(x,account);return [String(n.orderNo||''),Number(x?.list_type||0)]}));const rows=importResult.detail_refresh_order_nos.map(no=>({order_no:String(no),list_type:typeByOrder.get(String(no))||0}));autoDetail=await autoEnrichOrders(hub,options.tabId,account,rows);}
  const panOrders=Number.isFinite(Number(importResult.pan_purchase_orders))?Number(importResult.pan_purchase_orders):(Number.isFinite(Number(cancelledResult.pan_purchase_orders))?Number(cancelledResult.pan_purchase_orders):null);
  return {orderCount,itemCount:batch.length,ignored,cancelled,dateUnknown,shopeeRecords:raw.length,newUnique,duplicateRecords,seenOrderNos:[...seen],insertedOrders:Number(importResult.inserted_orders||0),updatedOrders:Number(importResult.updated_orders||0),cancelledDeleted:Number(cancelledResult.deleted||0),panOrders,autoDetailUpdated:autoDetail.ok,autoDetailErrors:autoDetail.errors,lastAutoDetailError:autoDetail.lastError};
}
function syncCounterPatch(st,rr){
  return {orders:Number(st.orders||0)+rr.orderCount,items:Number(st.items||0)+rr.itemCount,ignored:Number(st.ignored||0)+rr.ignored,cancelled:Number(st.cancelled||0)+rr.cancelled,dateUnknown:Number(st.dateUnknown||0)+rr.dateUnknown,shopeeRecords:Number(st.shopeeRecords||0)+rr.shopeeRecords,shopeeUniqueOrders:Number(st.shopeeUniqueOrders||0)+rr.newUnique,duplicateRecords:Number(st.duplicateRecords||0)+rr.duplicateRecords,panInsertedOrders:Number(st.panInsertedOrders||0)+rr.insertedOrders,panUpdatedOrders:Number(st.panUpdatedOrders||0)+rr.updatedOrders,cancelledDeleted:Number(st.cancelledDeleted||0)+rr.cancelledDeleted,autoDetailUpdated:Number(st.autoDetailUpdated||0)+Number(rr.autoDetailUpdated||0),autoDetailErrors:Number(st.autoDetailErrors||0)+Number(rr.autoDetailErrors||0),lastAutoDetailError:rr.lastAutoDetailError||st.lastAutoDetailError||'',panOrders:rr.panOrders===null?Number(st.panOrders||0):rr.panOrders,syncSeenOrderNos:rr.seenOrderNos};
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
    if(fresh)st={offset:0,orders:0,items:0,ignored:0,cancelled:0,dateUnknown:0,pages:0,done:false,paused:false,seenOffsets:[],scanId:crypto.randomUUID(),apiMode:'primary',statusIndex:0,statusOffset:0,statusSeenOffsets:[],shopeeRecords:0,shopeeUniqueOrders:0,duplicateRecords:0,panInsertedOrders:0,panUpdatedOrders:0,cancelledDeleted:0,panOrders:initialPanOrders,syncSeenOrderNos:[]};
    else if(st.panOrders===undefined)st.panOrders=initialPanOrders;
    if(!st.scanId)st.scanId=crypto.randomUUID();if(!st.apiMode)st.apiMode='primary';
    st=await setState(aid,{...st,accountUsername:account.username||account.nickname||'',accountId:aid,running:true,paused:false,error:'',done:false,job:'sync',status:'starting'});
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
        const rr=await processSyncRecords(raw,account,hub,st.scanId,page.url,st.syncSeenOrderNos||[]);
        const next=nextOffset(page.json,offset,limit,raw.length),seen=[...(st.seenOffsets||[]).slice(-300),offset];
        st=await setState(aid,{offset:next,...syncCounterPatch(st,rr),pages:Number(st.pages||0)+1,lastPageCount:raw.length,lastFormat:parsed.format,seenOffsets:seen,status:`primary page ${Number(st.pages||0)+1} · offset ${offset}`});
        if(next===-1||raw.length===0){if(Number(st.orders||0)>0){const rec=await hubJson(hub,'/api/reconcile.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({account_id:aid,scan_id:st.scanId})});if(rec?.ok===false)throw new Error(rec.error||'PAN reconcile ไม่สำเร็จ');}await setState(aid,{running:false,done:true,status:'completed',apiMode:'primary'});return;}
        await sleep(650+Math.floor(Math.random()*550));continue;
      }

      const statusIndex=Number(st.statusIndex||0);
      if(statusIndex>=statusTypes.length){if(Number(st.orders||0)>0){const rec=await hubJson(hub,'/api/reconcile.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({account_id:aid,scan_id:st.scanId})});if(rec?.ok===false)throw new Error(rec.error||'PAN reconcile ไม่สำเร็จ');}await setState(aid,{running:false,done:true,status:'completed · status fallback',apiMode:'status'});return;}
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
      const raw=parsed.records.map(x=>(x&&x.list_type===undefined)?{...x,list_type:listType}:x),rr=await processSyncRecords(raw,account,hub,st.scanId,page.url,st.syncSeenOrderNos||[]),next=nextOffset(page.json,offset,limit,raw.length),seen=[...statusSeen.slice(-500),`${listType}:${offset}`];
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
    if(fresh||st.done){
      const anchor=await hubJson(hub,`/api/sync_anchor.php?account_id=${encodeURIComponent(aid)}`);
      if(String(anchor.account_id)!==aid||!(anchor.cutoff_date===''||/^\d{4}-\d{2}-\d{2}$/.test(anchor.cutoff_date)))throw new Error('PAN ส่งข้อมูลจุดเริ่มต้นไม่ถูกต้อง');
      st={job:'recent',recentEndpoint:'primary',recentHub:hub,cutoff:anchor.cutoff_date,latestSavedDate:anchor.latest_order_date,recentIndex:0,recentOffset:0,recentOldPages:0,recentSeen:[],scanId:crypto.randomUUID(),pages:0,orders:0,items:0,ignored:0,cancelled:0,dateUnknown:0,shopeeRecords:0,shopeeUniqueOrders:0,duplicateRecords:0,panInsertedOrders:0,panUpdatedOrders:0,cancelledDeleted:0,panOrders:initialPanOrders,syncSeenOrderNos:[]};
    }
    st=await setState(aid,{...st,job:'recent',apiMode:'recent',accountUsername:account.username||account.nickname||'',running:true,paused:false,done:false,error:'',status:st.cutoff?`อัปเดตตั้งแต่ ${st.cutoff} · รวมช่วงทับซ้อนและรายการค้าง`:'ไม่มีวันที่อ้างอิงที่ปลอดภัย · ตรวจรายการทั้งหมดครั้งนี้'});
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
    await setState(aid,{running:false,done:true,status:'อัปเดตช่วงล่าสุดเสร็จ · เก็บข้อมูลเก่าครบตามเดิม'});
  }catch(e){if(aid)await setState(aid,{running:false,error:String(e.message||e),status:'error'});else throw e;}
  finally{runningJob=null;}
}
function reportRecentError(e){chrome.runtime.sendMessage({type:'SYNC_ERROR',error:String(e.message||e)}).catch(()=>{});}
chrome.runtime.onMessage.addListener((msg,sender,sendResponse)=>{(async()=>{
  if(msg?.type==='START_SYNC'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runSync(msg.tabId,true).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='START_RECENT'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runRecentSync(msg.tabId,true).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='RESUME_SYNC'){const a=await accountForTab(msg.tabId);const st=await getState(String(a.userid));if(st.job==='recent')runRecentSync(msg.tabId,false).catch(reportRecentError);else if(st.job==='repair'&&!st.done)runRepair(msg.tabId,true);else runSync(msg.tabId,false);sendResponse({ok:true});}
  else if(msg?.type==='START_REPAIR'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runRepair(msg.tabId,false,false).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='START_REPAIR_ALL'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runRepair(msg.tabId,false,true).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='START_PRODUCT_ENRICH'){if(runningJob)throw new Error('มีงาน Collector กำลังทำอยู่');runProductEnrichment(msg.tabId).catch(reportRecentError);sendResponse({ok:true});}
  else if(msg?.type==='PAUSE_SYNC'){const id=(await chrome.storage.local.get('lastAccountId')).lastAccountId||'';if(id)await setState(id,{paused:true,status:'pausing'});sendResponse({ok:true});}
  else if(msg?.type==='RESET_SYNC'){const id=String(msg.accountId||((await chrome.storage.local.get('lastAccountId')).lastAccountId||''));if(id){const all=await getAllStates();delete all[id];await chrome.storage.local.set({syncStates:all});}sendResponse({ok:true});}
  else if(msg?.type==='GET_STATE'){const id=String(msg.accountId||((await chrome.storage.local.get('lastAccountId')).lastAccountId||''));sendResponse({ok:true,state:await getState(id)});}
  else if(msg?.type==='GET_ACCOUNT'){try{const a=await accountForTab(msg.tabId);sendResponse({ok:true,account:a,state:await getState(String(a.userid))});}catch(e){sendResponse({ok:false,error:String(e.message||e)});}}
})().catch(e=>sendResponse({ok:false,error:String(e.message||e)}));return true;});
