# คู่มือติดตั้งแบบละเอียด

คู่มือนี้พาจากเครื่อง Android เปล่า ๆ ไปจนถึงหน้า dashboard ที่ `http://127.0.0.1:8080`
ใช้เวลาประมาณ 10–15 นาที (ขึ้นกับความเร็วในการติดตั้งแพ็กเกจ)

> โปรเจกต์นี้ใช้ **Termux + PHP CLI** ไม่ได้สร้าง APK
> จึงไม่ต้องติดตั้ง Android Studio, SDK หรือรูทเครื่อง

---

## 1. ตรวจสอบก่อนเริ่ม

- Android 7 ขึ้นไป
- พื้นที่ว่างอย่างน้อย 100 MB
- เชื่อมต่ออินเทอร์เน็ตสำหรับติดตั้งแพ็กเกจและ clone โปรเจกต์ (หลังจากนั้นใช้แบบออฟไลน์ได้)
- ยังไม่มี Termux จาก Play Store — ถ้ามีให้ถอนก่อน

**ห้ามผสม Termux จาก Play Store กับ F-Droid** เพราะลายเซ็นแอปต่างกัน
แพ็กเกจที่ติดตั้งไว้จะใช้ร่วมกันไม่ได้ และอาจเกิดข้อผิดพลาดแปลก ๆ

---

## 2. ติดตั้ง Termux จาก F-Droid

1. เปิด <https://f-droid.org/> ในเบราว์เซอร์ของมือถือ
2. ดาวน์โหลดและติดตั้ง F-Droid (อาจต้องอนุญาต **Install unknown apps** ให้เบราว์เซอร์)
3. เปิด F-Droid รอให้ดัชนีซอฟต์แวร์โหลดเสร็จ
4. ค้นหา **Termux** (`com.termux`) แล้วกด **Install**
5. เปิด Termux หนึ่งครั้ง

ถ้าต้องการเปิดเซิร์ฟเวอร์อัตโนมัติเมื่อบูต:

6. ค้นหา **Termux:Boot** (`com.termux.boot`) แล้วติดตั้ง
7. เปิดแอป Termux:Boot หนึ่งครั้ง

> Termux:Boot ต้องมาจากแหล่งเดียวกับ Termux (F-Droid) และต้องเปิดแอปอย่างน้อยหนึ่งครั้ง
> ไม่เช่นนั้นระบบจะไม่รันสคริปต์ใน `~/.termux/boot/`

---

## 3. ตั้งค่า Termux ครั้งแรก

เปิด Termux แล้วรัน:

```bash
pkg update -y
pkg upgrade -y
```

ถ้ามีคำถามระหว่างติดตั้ง ให้เลือกค่าที่แนะนำ (ค่าเริ่มต้น) ได้เลย

ติดตั้งเครื่องมือพื้นฐาน:

```bash
pkg install -y git php curl unzip nano
```

- `php` — PHP CLI และ built-in server
- `curl` — ใช้ตรวจสอบสถานะและเปิด URL
- `unzip` — ใช้แตกไฟล์ ZIP ตอน import
- `nano` — ใช้แก้ไฟล์ config (จะใช้ `vi` แทนก็ได้)

ตรวจสอบ:

```bash
php -v
```

ควรเห็นข้อความประมาณ `PHP 8.x.x (cli)` — โปรเจกต์นี้ต้องการ PHP 8.1 ขึ้นไป

### เปิดสิทธิ์เข้าถึง Downloads

```bash
termux-setup-storage
```

จะมีหน้าต่างของ Android ขออนุญาตเข้าถึงไฟล์ → กด **Allow**

ตรวจสอบว่ามีโฟลเดอร์ Downloads:

```bash
ls ~/storage/downloads
```

ถ้าเจอ `Permission denied` หรือไม่มีโฟลเดอร์ ให้รัน `termux-setup-storage` ใหม่
และตรวจสิทธิ์ใน Settings → Apps → Termux → Permissions → Files and media

---

## 4. ดาวน์โหลดโปรเจกต์

เลือกวิธีใดวิธีหนึ่ง

### วิธี A — ใช้ Git (แนะนำ)

```bash
cd ~
git clone https://github.com/NarDecH/android-php-server.git
cd android-php-server
```

### วิธี B — ดาวน์โหลด ZIP จาก GitHub

1. เปิดหน้า GitHub ของโปรเจกต์ในเบราว์เซอร์
2. กด **Code → Download ZIP**
3. ไฟล์จะอยู่ใน Downloads ของมือถือ
4. กลับมาที่ Termux:

```bash
cd ~
unzip ~/storage/downloads/android-php-server-main.zip
cd android-php-server-main
```

ชื่อโฟลเดอร์หลังแตกไฟล์อาจเป็น `android-php-server-main` หรือตามชื่อ branch
ให้ใช้ `ls` ดูแล้ว `cd` ให้ถูก

> **สำคัญ:** ควรเก็บโปรเจกต์ไว้ใน home ของ Termux (`~/...`)
> ไม่ควร clone ลงใน `~/storage/shared` เพราะ Android shared storage จำกัดสิทธิ์
> และอาจทำให้ PHP เขียนไฟล์/execute ไม่ได้

---

## 5. รันสคริปต์ตั้งค่า

```bash
bash scripts/setup.sh
```

ตัวอย่างผลลัพธ์:

```text
=== PHP Server on Android — ตั้งค่าเริ่มต้น ===

[1/3] ตรวจพบ Termux — กำลังติดตั้ง PHP, curl และ unzip
...
[2/3] เตรียมโฟลเดอร์เว็บและโฟลเดอร์สถานะ
      สร้างไฟล์ตั้งค่า: /data/data/com.termux/files/home/.php-server/config.env
[3/3] ตรวจสอบสิทธิ์เข้าถึง Downloads
      พบ ~/storage/downloads แล้ว

พร้อมใช้งาน! ขั้นตอนถัดไป:
  bash scripts/server.sh start
  bash scripts/server.sh open
```

สคริปต์จะ:

- ติดตั้ง `php`, `curl`, `unzip` (ถ้ายังไม่มี)
- สร้าง `www/files/` สำหรับไฟล์ที่นำเข้า
- สร้าง `~/.php-server/config.env` (ครั้งแรกเท่านั้น)
- ตั้งสิทธิ์ไฟล์ config เป็น 600

ตรวจไฟล์ตั้งค่า:

```bash
cat ~/.php-server/config.env
```

---

## 6. เริ่มเซิร์ฟเวอร์

```bash
bash scripts/server.sh start
```

ผลลัพธ์ที่ควรเห็น:

```text
กำลังเริ่ม PHP server ที่พอร์ต 8080…
✓ เริ่มทำงานแล้ว (PID 12345)
  เปิดเบราว์เซอร์: http://127.0.0.1:8080
  ฟังเฉพาะเครื่องนี้ (127.0.0.1) — เครื่องอื่นเข้าถึงไม่ได้
```

เปิดเบราว์เซอร์ในมือถือไปที่:

```text
http://127.0.0.1:8080
```

หรือให้ Termux เปิดให้:

```bash
bash scripts/server.sh open
```

ตรวจสอบแบบไม่ใช้เบราว์เซอร์:

```bash
curl -s http://127.0.0.1:8080/healthz
# ok

bash scripts/server.sh status
```

ถ้าเห็น `Health : ตอบสนองปกติ` แสดงว่าเซิร์ฟเวอร์พร้อมใช้งาน

---

## 7. เปิดอัตโนมัติเมื่อบูต (ไม่บังคับ)

ต้องมี **Termux:Boot** จาก F-Droid และเปิดแอปอย่างน้อยหนึ่งครั้งก่อน

```bash
cd ~/android-php-server
bash scripts/install-boot.sh
```

สคริปต์จะสร้าง `~/.termux/boot/php-server.sh` ซึ่งเรียก `server.sh start`
หลังเครื่องบูตประมาณ 8 วินาที

ทดสอบทันทีโดยไม่ต้องรีบูต:

```bash
bash ~/.termux/boot/php-server.sh
cat ~/.php-server/boot.log
```

หลังรีบูต เครื่องอาจต้องใช้เวลา 10–30 วินาที กว่าจะเปิดเว็บได้

**เพิ่มโอกาสให้อยู่รอด:**

- Settings → Apps → Termux → Battery → **Unrestricted**
- เปิด Termux ค้างไว้ (แตะ notification ของ wake lock ถ้ามี)
- อย่าปัด Termux ออกจาก recent apps ถ้าเครื่องจัดการหน่วยความจำเข้มงวด

ยกเลิก:

```bash
bash scripts/install-boot.sh --remove
```

---

## 8. อัปเดตโปรเจกต์

สำรองไฟล์ที่แก้ไขเองก่อน (ถ้ามี) แล้วรัน:

```bash
cd ~/android-php-server
bash scripts/server.sh stop
git pull --ff-only
bash scripts/setup.sh
bash scripts/server.sh start
```

- ไฟล์ใน `www/files/` และ `~/.php-server/config.env` จะไม่ถูกเขียนทับ
- ถ้าแก้ `www/index.php` เองแล้ว `git pull` มี conflict ต้องแก้ conflict ก่อน
- ถ้าติดตั้งจาก ZIP ให้ดาวน์โหลด ZIP ใหม่แล้วย้าย `www/files/` กับ config ไปไว้ในโฟลเดอร์ใหม่

---

## 9. ถอนการติดตั้ง

> คำสั่ง `rm -rf` จะลบไฟล์ถาวร — ตรวจ path ให้ถูกและสำรองไฟล์ก่อน

```bash
cd ~/android-php-server
bash scripts/install-boot.sh --remove
bash scripts/server.sh stop
cd ~
rm -rf ~/android-php-server
rm -rf ~/.php-server
```

จากนั้นถอนแอป Termux / Termux:Boot ผ่าน Settings ได้ตามต้องการ

ไฟล์ต้นฉบับที่ยังอยู่ใน Downloads ของมือถือจะไม่ถูกลบ

---

## เช็กลิสต์หลังติดตั้ง

- [ ] `php -v` แสดง PHP 8.1+
- [ ] `bash scripts/server.sh start` ทำงาน
- [ ] เปิด `http://127.0.0.1:8080` เห็น dashboard
- [ ] เปิด `upload.php` อัปโหลดไฟล์ทดสอบได้
- [ ] `bash scripts/server.sh status` ขึ้น health ปกติ
- [ ] (ถ้าใช้ LAN) ตั้ง `UPLOAD_PIN` และเปิดจากเครื่องอื่นได้
- [ ] (ถ้าใช้ autostart) Termux:Boot ติดตั้งและทดสอบแล้ว

ถ้าติดขั้นตอนไหน ดู [troubleshooting.md](troubleshooting.md)
