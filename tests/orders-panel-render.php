<?php
/** Synthetic template-level read-only Orders product expansion regression. */
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/order_timeline.php';
require_once $root.'/app/order_items_view.php';
function h(mixed $v):string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function money(mixed $v):string { return '฿'.number_format((float)$v,2); }
function order_status_th(string $s, ?int $n=null):string { return $s ?: 'สำเร็จแล้ว'; }
function detail_state_label(array $o):string { return 'ตรวจแล้ว'; }
$php=file_get_contents($root.'/index.php');
$start=strpos($php,'<?php foreach($orders as $o):?><tr class="order-master-row"');
$end=$start!==false?strpos($php,'<?php endforeach;?></tbody></table></div>',$start):false;
if($start===false||$end===false)throw new RuntimeException('Order product markup anchor not found');
$template=substr($php,$start,$end-$start+strlen('<?php endforeach;?>'));
$orders=[
 ['id'=>3,'order_no'=>'ORDER-123','order_created_at'=>'2026-10-09 18:40:00',
 'date_source'=>'info_card.create_time','order_date'=>'2026-10-09','shop_name'=>'Shop',
 'qty'=>3,'raw_subtotal'=>100,'subtotal'=>100,'discount_total'=>0,'total_paid'=>90,
 'list_type'=>3,'order_status'=>'completed','validation_state'=>'verified_v200',
 'source_account_username'=>'fixture','source_account_id'=>'sample'],
 ['id'=>4,'order_no'=>'ORDER-EMPTY','order_created_at'=>'2026-10-10',
 'date_source'=>'info_card.create_time','order_date'=>'2026-10-10','shop_name'=>'Store',
 'qty'=>0,'raw_subtotal'=>0,'subtotal'=>0,'discount_total'=>0,'total_paid'=>0,
 'list_type'=>7,'order_status'=>'shipping','validation_state'=>'verified_v200',
 'source_account_username'=>'fixture','source_account_id'=>'sample']
];
$orderItemsByOrder=[3=>[
 ['product_name'=>'Foo <script>alert(1)</script>','variant_name'=>'Red <img onerror=alert(1)>',
  'image_url'=>'javascript:alert(1)','product_url'=>'javascript:alert(1)',
  'quantity'=>2,'actual_unit_price'=>25,'actual_line_total'=>50],
 ['product_name'=>'Second Product','variant_name'=>'Blue','image_url'=>'https://example.org/img.jpg',
  'product_url'=>'https://shopee.co.th/item','quantity'=>1,'actual_unit_price'=>40,'actual_line_total'=>40],
],4=>[]];
ob_start();
try { eval('?>'.$template);$html=ob_get_clean(); }
catch(Throwable $e){ob_end_clean();throw $e;}
$checks=[
 'both order IDs have distinct hidden detail rows' => str_contains($html,'id="pan-order-lines-3"') && str_contains($html,'id="pan-order-lines-4"'),
 'two products rendered inside selected Order detail' => str_contains($html,'Foo &lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($html,'Second Product'),
 'malicious unescaped tag not rendered' => !str_contains($html,'<script>alert(1)</script>')&&!str_contains($html,'<img onerror='),
 'variant HTML safely escaped' => str_contains($html,'Red &lt;img onerror=alert(1)&gt;'),
 'unsafe image and link schemes rejected' => !str_contains($html,'src="javascript:')&&!str_contains($html,'href="javascript:'),
 'safe external links are rendered securely' => str_contains($html,'href="https://shopee.co.th/item" target="_blank" rel="noopener noreferrer"'),
 'image uses lazy load' => str_contains($html,'loading="lazy"'),
 'missing product data is distinguished from missing purchase' => str_contains($html,'ไม่ได้หมายความว่าไม่มีการสั่งซื้อจริง'),
 'per-line amounts and quantity are visible' => str_contains($html,'฿50.00') && str_contains($html,'฿40.00') && str_contains($html,'3 ชิ้น'),
 'both details are collapsed by default' => substr_count($html,'class="order-products-row" hidden')===2,
];
foreach($checks as $label=>$ok){
 if(!$ok)throw new RuntimeException('FAIL: '.$label);
 echo 'PASS '.$label."\n";
}
