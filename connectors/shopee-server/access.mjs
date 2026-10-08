export function accessIssue(state = {}, error = '') {
  const detail = [error, state.error || ''].join(' ');
  if (/90309999|Shopee anti-fraud/.test(detail)) return {
    code: 'shopee_rejected',
    message: 'Shopee ปฏิเสธคำขอดึงข้อมูล (90309999) แม้ล็อกอินสำเร็จ PAN พักการซิงก์ไว้ กรุณาตรวจหน้า Shopee และยืนยันตัวตนหากมีคำขอ แล้วกดตรวจสิทธิ์ดึงข้อมูล ไม่มีการลองซ้ำอัตโนมัติ'
  };
  if (/Shopee HTTP (401|403)|SESSION_BLOCK|กรุณาเข้าสู่ระบบ Shopee หรือยืนยันตัวตนใหม่/.test(detail)) return {
    code: 'login_required',
    message: 'เซสชัน Shopee ใช้ดึงข้อมูลไม่ได้ กรุณาตรวจการเข้าสู่ระบบหรือยืนยันตัวตน แล้วกดตรวจสิทธิ์ดึงข้อมูล'
  };
  return null;
}
