# แก้ปัญหา

เอกสารนี้รวบรวมอาการที่พบบ่อยและวิธีแก้ ทุกคำสั่งรันใน Termux จากโฟลเดอร์โปรเจกต์
(ยกเว้นคำสั่งที่ระบุ path เต็ม)

---

## ก่อนตรวจอะไรทั้งหมด: เก็บข้อมูล 3 อย่าง

```bash
bash scripts/server.sh status
tail -n 50 ~/.php-server/server.log
tail -n 50 ~/.php-server/php-error.log
```

ถ้าเป็นปัญหาเฉพาะหน้า ให้ทดสอบ health:

```bash
curl -i http://127.0.0.1:8080/healthz
```

ผลที่ควรได้:

```text
HTTP/1.1 200 OK
Content-Type: text/plain; charset=utf-8
...
ok
```

---

## 1. ปัญหาการติดตั้ง / Termux

### `pkg: command not found`

คุณไม่ได้อยู่ใน Termux หรือ PATH เพี้ยน ให้ปิดแอปแล้วเปิดใหม่
และตรวจว่าได้ติดตั้ง Termux จาก F-Droid ไม่ใช่ terminal อื่น

### `E: Unable to locate package php`

```bash
pkg update -y
pkg upgrade -y
pkg install -y php curl unzip
```

ถ้ายังไม่พบ ให้ตรวจว่า repositories ของ Termux ยังชี้ไปที่ official mirror
(โดยปกติ `pkg update` จะแก้ให้)

### `CANNOT LINK EXECUTABLE "php": ... SSL_set_quic_tls_early_data_enabled`

อาการนี้เกิดเมื่อ `pkg update` เปลี่ยน PHP เป็นรุ่นใหม่ แต่ `libcurl`/`openssl` ยังเป็นรุ่นเก่า
และ `pkg upgrade` อาจพังต่อเพราะ `curl` เองก็ link ไม่ได้

ให้ซ่อมด้วย `apt` โดยตรง (ไม่ผ่าน `curl`):

```bash
apt update
apt -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold full-upgrade -y
pkg install -y php curl unzip
php -v
```

ถ้า `php -v` ยังพัง ให้ปิด/เปิด Termux แล้วรัน `bash scripts/setup.sh` อีกครั้ง
สคริปต์ setup ตรวจและซ่อมกรณีนี้ให้อัตโนมัติ

### `apt` ค้างที่ `Configuration file ... openssl.cnf`

`DEBIAN_FRONTEND=noninteractive` อย่างเดียวไม่พอ ถ้ามีคำถาม conffile ให้บังคับใช้ค่าเดิม:

```bash
apt -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold full-upgrade -y
```

หรือปล่อยให้ `bash scripts/setup.sh` จัดการ (รุ่นนี้ใส่ option นี้ให้แล้ว)

### ติดตั้ง Termux จาก Play Store ไว้

Play Store version เลิกดูแลและอาจติดตั้งแพ็กเกจไม่ได้
ถอนออก แล้วติดตั้งจาก F-Droid ใหม่
(อย่าติดตั้งทับกัน เพราะลายเซ็นต่างกัน)

### `termux-setup-storage` ไม่ขึ้นหน้าต่างขออนุญาต

1. ไปที่ Settings → Apps → Termux → Permissions → Files and media → Allow
2. Android 11 ขึ้นไปบางรุ่นไม่มีตัวเลือกนี้ ให้ไปที่
   Settings → Apps → Termux → Special app access → **All files access** → Allow
3. กลับมาที่หน้าจอ Termux (ต้องเปิดแอปค้างไว้) แล้วรัน `termux-setup-storage` ใหม่
4. ตรวจ `ls ~/storage/downloads` ว่าอ่านไฟล์ได้จริง

### พื้นที่เต็มระหว่างติดตั้ง

```bash
df -h "$HOME"
pkg clean
```

ลบไฟล์ที่ไม่ใช้ใน Downloads หรือ `www/files/` แล้วลองใหม่

---

## 2. เซิร์ฟเวอร์เริ่มไม่ทำงาน

### `php: command not found`

```bash
bash scripts/setup.sh
# หรือ
pkg install -y php
```

### `ผิดพลาด: ยังไม่มีไฟล์ตั้งค่า`

```bash
bash scripts/setup.sh
```

### `พอร์ต 8080 ถูกใช้งานอยู่`

ดูว่ามีเซิร์ฟเวอร์ตัวเก่ารันอยู่หรือไม่:

```bash
bash scripts/server.sh status
bash scripts/server.sh stop
```

ถ้ายังไม่หยุด ให้เปลี่ยนพอร์ต:

```bash
nano ~/.php-server/config.env
# PORT=8081
bash scripts/server.sh restart
```

หรือหาว่าใครใช้พอร์ต (ถ้าติดตั้ง `lsof`):

```bash
pkg install -y lsof
lsof -i :8080
```

ห้ามใช้ `pkill -f php` เพราะอาจไปฆ่าโปรเซส PHP อื่นที่สำคัญ

### เริ่มแล้วขึ้น `เริ่มเซิร์ฟเวอร์ไม่สำเร็จ`

อ่าน log:

```bash
tail -n 50 ~/.php-server/server.log
cat ~/.php-server/php-error.log
```

สาเหตุที่พบบ่อย:

- พอร์ตชน
- PHP ถูกติดตั้งไม่สมบูรณ์ → `pkg reinstall php`
- โฟลเดอร์ `www/` หาย → `ls www/`
- `router.php` มี syntax error → `php -l router.php`

### `status` ขึ้นหยุดอยู่ แต่ browser ยังเปิดได้

อาจมี PHP server ตัวเก่าจากการรันด้วยมือค้างอยู่ ให้เปลี่ยนพอร์ตหรือหา process:

```bash
ps -ef | grep '[p]hp'
```

ถ้าเป็น process ที่รู้จัก ให้หยุดด้วย PID:

```bash
kill <PID>
```

อย่าใช้ pattern kill เพราะเสี่ยงฆ่า process อื่น

### PID ค้าง / สถานะไม่ตรง

```bash
bash scripts/server.sh stop
bash scripts/server.sh start
```

สคริปต์จะตรวจ PID และ cmdline ก่อน ไม่ฆ่า process ที่ไม่เกี่ยวข้อง

---

## 3. เปิดเว็บไม่ได้ / หน้าขาว / error

### เปิด `http://127.0.0.1:8080` ไม่ได้

1. ตรวจว่า start แล้ว:

```bash
bash scripts/server.sh status
```

2. ทดสอบด้วย curl:

```bash
curl -v http://127.0.0.1:8080/
```

3. ถ้า curl ได้แต่ browser ไม่ได้ ให้ลอง Chrome และปิด VPN/Private DNS ชั่วคราว

### หน้าเว็บขาวหรือ 500

ดู PHP error:

```bash
tail -n 100 ~/.php-server/php-error.log
```

ตรวจ syntax:

```bash
php -l www/index.php
php -l www/upload.php
php -l www/_lib.php
php -l router.php
```

ถ้าเพิ่งแก้โค้ด ให้แก้ตาม error แล้วรัน `bash scripts/server.sh restart`

### 404 ทุกหน้า

- ตรวจว่า `www/index.php` มีอยู่จริง
- ตรวจว่า start ด้วย `scripts/server.sh` (ใช้ `router.php`) ไม่ใช่ `php -S` เปล่า ๆ
- ถ้าเปิด path ของโฟลเดอร์ที่ไม่มี `index.php` จะได้ 404 เป็นการตั้งใจ
  (ปิด directory listing) ให้เปิดไฟล์โดยตรง

### ไฟล์ CSS/JS ไม่โหลด

ตรวจว่าไฟล์อยู่ที่ `www/assets/` และชื่อตรงกับที่อ้างใน HTML
ลองเปิด `http://127.0.0.1:8080/assets/app.css` ตรง ๆ

### ไฟล์ `.php` แสดงเป็นข้อความ

- ต้องเปิดผ่าน `http://127.0.0.1:8080/...` ไม่ใช่ `file://` หรือ file manager
- ตรวจว่า PHP server ทำงาน (ไม่ใช่ static file server อื่น)
- ถ้าเพิ่งอัปโหลด ให้รีเฟรชหน้า

### เปิด `/files/` แล้ว 404

เป็นการตั้งใจเพื่อความปลอดภัย — ไม่มี directory listing
ให้เปิดไฟล์หรือโฟลเดอร์ที่มี `index.php` โดยตรง เช่น
`http://127.0.0.1:8080/files/mysite/`

---

## 4. อัปโหลดไฟล์

### `อัปโหลดไม่สำเร็จ` / ไฟล์ใหญ่เกิน

ดูค่าปัจจุบันได้ที่ dashboard หรือ config:

```bash
bash scripts/server.sh config
```

เพิ่ม:

```bash
nano ~/.php-server/config.env
# MAX_UPLOAD_MB=128
bash scripts/server.sh restart
```

ถ้าเกิน `post_max_size` ระบบจะแจ้งข้อผิดพลาดชัดเจน
ถ้ายังไม่ได้ ให้ตรวจว่า PHP ที่ start รับ `-d` จากสคริปต์จริง
(ดู `ps -ef | grep '[p]hp'`)

ไฟล์ใหญ่มากแนะนำใช้:

```bash
bash scripts/import.sh ~/storage/downloads/ไฟล์.zip
```

### เลือกไฟล์แล้วกดอัปโหลด ไม่มีอะไรเกิดขึ้น

- ตรวจว่าเลือกไฟล์จริง (รายการไฟล์ที่เลือกควรแสดง)
- เปิด Console ของ Chrome ดู error (ถ้าถนัด)
- ลองปิด JavaScript แล้วใช้ฟอร์มปกติ
- ตรวจ `www/files/` เขียนได้:

```bash
touch www/files/.test-write && rm www/files/.test-write
```

### อัปโหลดแล้วไฟล์หาย

- ดูรายการใน `upload.php`
- ตรวจ `ls -la www/files/`
- ตรวจว่าอัปโหลดไปที่ `www/files/` ไม่ใช่โฟลเดอร์อื่น
- ไฟล์ชื่อซ้ำจะถูกเปลี่ยนชื่อเป็น `-1`, `-2` ไม่ได้หาย

### อัปโหลด `.php` แล้ว execute ทันที

เป็นพฤติกรรมที่ตั้งใจ — ไฟล์ PHP ทุกไฟล์ใน `www/` ถูก execute
อย่าอัปโหลดโค้ดที่ไม่ไว้ใจ

---

## 5. นำเข้าจาก Downloads

### `ไม่พบโฟลเดอร์ Downloads`

```bash
termux-setup-storage
ls ~/storage/downloads
```

กด Allow ในหน้าต่าง Android แล้วลองใหม่
ถ้าใช้ Android 11 ขึ้นไป ให้ตรวจ **All files access** ตามหัวข้อด้านบนด้วย

### `อ่านโฟลเดอร์ Downloads ไม่ได้ (สิทธิ์ถูกปฏิเสธ)`

Termux ถูกถอนสิทธิ์หรือยังไม่ได้กด Allow:

```bash
termux-setup-storage
ls -ld ~/storage/downloads
```

ถ้ายังไม่ได้ ให้ตรวจสิทธิ์ใน Settings ตามหัวข้อ `termux-setup-storage` ด้านบน
แล้วรัน `bash scripts/import.sh` ใหม่

### `Permission denied` ตอนคัดลอก

- ตรวจว่าไฟล์ต้นทางอ่านได้:

```bash
ls -l ~/storage/downloads/<ชื่อไฟล์>
```

- อย่า clone โปรเจกต์ไว้ใน `~/storage/shared`
- ตรวจสิทธิ์ `www/files`:

```bash
ls -ld www/files
```

### `ไม่พบต้นทาง`

- ใส่ path ผิด หรือใช้ `~` ในเครื่องหมายคำพูดแล้ว script ขยายให้ไม่ถูก
- ลองใส่ path เต็ม:

```bash
bash scripts/import.sh "$HOME/storage/downloads/mysite.zip"
```

### `ปลายทางมีไฟล์อยู่แล้ว`

ใช้ `--force` เพื่อเขียนทับ/รวม หรือเปลี่ยนชื่อโฟลเดอร์:

```bash
bash scripts/import.sh ~/storage/downloads/mysite --to mysite-v2
```

### ZIP แตกไม่สำเร็จ

```bash
pkg install -y unzip
unzip -l ~/storage/downloads/mysite.zip
```

ถ้า ZIP เสีย ให้ดาวน์โหลดใหม่

### เปิด URL ที่สคริปต์บอกแล้ว 404

ZIP อาจมีโฟลเดอร์ชั้นใน:

```bash
find www/files/mysite -maxdepth 2 -type f | head -20
```

แล้วเปิด path ตามจริง เช่น `/files/mysite/public/index.php`

---

## 6. PIN / Login

### ใส่ PIN ถูกแต่กลับหน้า login

- ตรวจว่าตั้ง PIN แล้ว restart จริง:

```bash
bash scripts/server.sh restart
```

- เบราว์เซอร์ต้องอนุญาต cookie
- อย่าเปิดในโหมดที่บล็อก cookie
- ลองล้างข้อมูลเว็บของ `127.0.0.1:8080` แล้ว login ใหม่
- ตรวจว่าไม่ได้ใช้ `https://` (ระบบนี้เป็น HTTP)

### ลืม PIN

```bash
nano ~/.php-server/config.env
# UPLOAD_PIN=  (เว้นว่าง) หรือตั้งใหม่
bash scripts/server.sh restart
```

### เปลี่ยน PIN แล้ว session เก่ายังใช้ได้?

ระบบผูก session กับ SHA-256 ของ PIN แล้ว — เปลี่ยน PIN แล้ว session เก่าจะใช้ไม่ได้
ถ้ายังเจอปัญหา ให้ล้าง cookie ของเว็บนี้

### ถูก redirect ไป upload.php ตลอด

นี่คือพฤติกรรมเมื่อตั้ง PIN และยังไม่ login
ถ้าไม่ต้องการ PIN ให้ตั้ง `UPLOAD_PIN=` แล้ว restart

### ต้องการ logout

เปิด `upload.php` → การ์ด **ความปลอดภัย** → ปุ่ม **ล็อกเอาต์**

---

## 7. เปิดจากเครื่องอื่น (LAN)

### เชื่อมต่อไม่ได้

ตรวจตามลำดับ:

1. ทั้งสองเครื่องอยู่ใน Wi-Fi เดียวกัน
2. `HOST=0.0.0.0` ใน config และ restart แล้ว
3. `bash scripts/server.sh status` ขึ้นกำลังทำงาน
4. IP ใน URL ถูกต้อง (ดูจาก Settings → About phone)
5. ปิด VPN ทั้งสองเครื่องชั่วคราว
6. router ไม่ได้เปิด AP/client isolation
7. ลองใช้ hotspot ของมือถือแล้วเชื่อมคอมเข้า hotspot

ทดสอบจากมือถือเองก่อน:

```bash
curl -s http://127.0.0.1:8080/healthz
```

ถ้าคำสั่งนี้ผ่าน แต่เครื่องอื่นเข้าไม่ได้ ให้ดู IP จาก Settings ของมือถือ
และตรวจ Wi-Fi/AP isolation ตามด้านบน

### เปิดได้แต่ขึ้น 404/redirect

- ถ้าตั้ง PIN จะถูก redirect ไป login — ใส่ PIN ให้ถูก
- อย่าเปิด `https://` (ไม่มี TLS)
- ตรวจ path เช่น `/files/mysite/` ต้องมี `/` ปิดท้าย

### เปิดจากอินเทอร์เน็ตไม่ได้

เป็นการตั้งใจ — โปรเจกต์นี้ออกแบบสำหรับ local/LAN
หากต้องการ public ต้องใช้ reverse proxy + HTTPS + authentication ที่แข็งแรง
และไม่ควรใช้ PHP built-in server โดยตรง

---

## 8. เปิดอัตโนมัติเมื่อบูต

### หลังรีบูตเว็บไม่ขึ้น

1. ตรวจว่าติดตั้ง Termux:Boot จาก F-Droid และเปิดแอปอย่างน้อยหนึ่งครั้ง
2. รอ 1–3 นาทีหลังบูต เพราะ Termux:Boot ใช้ JobScheduler และ Android/HyperOS อาจหน่วง
3. ตรวจว่าไฟล์ boot ถูกสร้าง:

```bash
ls -l ~/.termux/boot/php-server.sh
cat ~/.php-server/boot.log
```

4. ตรวจ battery optimization ของ Termux และเปิด **Autostart**
   (Xiaomi/HyperOS: Settings → Apps → Termux → Autostart; OPPO/vivo มีเมนูคล้ายกัน)
5. ล็อก Termux ไว้ใน recent apps และอนุญาต **Background activity**
6. เปิด Termux เองแล้วดูว่าเซิร์ฟเวอร์ขึ้นหรือไม่:

```bash
bash scripts/server.sh status
```

7. Android บางรุ่นต้องปิด **Phantom process killer** หรืออนุญาต background execution
   (ขึ้นกับผู้ผลิต)

ถ้า `boot.log` ว่างหรือขึ้น `php ทำงานไม่ได้` ให้ตรวจว่า PHP ยังเรียกได้:

```bash
php -v
bash scripts/setup.sh
```

### `env: 'bash': No such file or directory` ใน `boot.log`

สคริปต์ boot รุ่นเก่าใช้ `#!/usr/bin/env bash` ซึ่งหา `bash` ไม่เจอในสิ่งแวดล้อมตอนบูต
รุ่นนี้เขียน shebang เป็น path เต็มของ Termux แล้ว ให้รันคำสั่งนี้ทับไฟล์เดิม:

```bash
cd ~/android-php-server
bash scripts/install-boot.sh
bash ~/.termux/boot/php-server.sh
cat ~/.php-server/boot.log
```

### `install-boot.sh` บอกไม่พบ config

รัน `bash scripts/setup.sh` ก่อน

### ต้องการยกเลิก

```bash
bash scripts/install-boot.sh --remove
```

### ย้ายโฟลเดอร์โปรเจกต์แล้ว boot ไม่ทำงาน

สคริปต์ boot เก็บ path เต็มไว้ ให้รัน `bash scripts/install-boot.sh` ใหม่
(ลบไฟล์เดิมทับ)

---

## 9. รีเซ็ตระบบ

### หยุดทุกอย่าง

```bash
bash scripts/server.sh stop
bash scripts/install-boot.sh --remove
```

### ล้าง config แต่เก็บไฟล์เว็บ

```bash
rm ~/.php-server/config.env
bash scripts/setup.sh
bash scripts/server.sh start
```

### ล้าง log

```bash
rm -f ~/.php-server/server.log ~/.php-server/php-error.log ~/.php-server/boot.log
bash scripts/server.sh restart
```

### ต้องการเริ่มจากศูนย์ (ลบไฟล์ทั้งหมด)

> สำรองก่อน เพราะคำสั่งนี้ลบถาวร

```bash
cd ~
bash android-php-server/scripts/server.sh stop
rm -rf ~/android-php-server ~/.php-server
```

---

## 10. ตรวจสอบเวอร์ชันและสภาพแวดล้อม

```bash
php -v
php -m | head -30
uname -a
bash scripts/server.sh config
```

ข้อมูลที่มีประโยชน์เมื่อขอความช่วยเหลือ:

- รุ่นมือถือและ Android version
- เวอร์ชัน Termux และ PHP
- ข้อความ error จาก `server.log` / `php-error.log`
- คำสั่งที่ใช้และผลลัพธ์

อย่าแชร์ไฟล์ `config.env` ที่มี PIN จริง
