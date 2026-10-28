# คู่มือใช้งานและตั้งค่า

เอกสารนี้สรุปทุกคำสั่งและพฤติกรรมของระบบ หลังติดตั้งตาม
[installation.md](installation.md) แล้ว

---

## 1. โครงสร้างการทำงาน

- เซิร์ฟเวอร์คือ **PHP built-in server** ฟังที่ `127.0.0.1:8080` โดยค่าเริ่มต้น
- ไฟล์เว็บทั้งหมดอยู่ใต้ `www/`
- หน้าแรก `www/index.php` เป็น dashboard
- หน้า `www/upload.php` ใช้ upload/delete และ login PIN
- ไฟล์ที่นำเข้าอยู่ใน `www/files/` (ไม่ถูก commit ขึ้น Git)
- `router.php` ทำหน้าที่:
  - ให้ `/healthz` สำหรับ health check
  - กัน path traversal และไฟล์ซ่อน
  - ปิด directory listing
  - ตรวจ session เมื่อตั้ง `UPLOAD_PIN`

---

## 2. คำสั่งจัดการเซิร์ฟเวอร์

รันจากโฟลเดอร์โปรเจกต์ (`~/android-php-server`):

| คำสั่ง | ผลลัพธ์ |
|---|---|
| `bash scripts/server.sh start` | เริ่มเซิร์ฟเวอร์เบื้องหลัง + รอจน health ผ่าน |
| `bash scripts/server.sh stop` | หยุดเซิร์ฟเวอร์ + ปล่อย wake lock |
| `bash scripts/server.sh restart` | หยุดแล้วเริ่มใหม่ (ใช้หลังแก้ config) |
| `bash scripts/server.sh status` | แสดง PID, URL, สถานะ health |
| `bash scripts/server.sh logs` | แสดง log 80 บรรทัดล่าสุด |
| `bash scripts/server.sh open` | เปิด URL ด้วยเบราว์เซอร์ Android |
| `bash scripts/server.sh url` | พิมพ์ URL |
| `bash scripts/server.sh config` | แสดงค่าตั้งค่า (ซ่อน PIN) |
| `bash scripts/server.sh help` | แสดงคำช่วยเหลือ |

ตัวอย่าง:

```bash
bash scripts/server.sh status
```

```text
สถานะ : กำลังทำงาน
PID    : 12345
URL    : http://127.0.0.1:8080
Health : ตอบสนองปกติ (/healthz)
Config : /data/data/com.termux/files/home/.php-server/config.env
Log    : /data/data/com.termux/files/home/.php-server/server.log
```

### ถ้าเริ่มไม่สำเร็จ

สคริปต์จะพิมพ์ log ท้ายสุดให้อัตโนมัติ ดูเพิ่มได้ที่:

```bash
bash scripts/server.sh logs
```

### เบื้องหลังการทำงาน

- PID เก็บที่ `~/.php-server/server.pid`
- Log หลัก: `~/.php-server/server.log`
- PHP error log: `~/.php-server/php-error.log`
- ตัวเลือก PHP ที่สคริปต์ใส่ให้:
  - `upload_max_filesize = MAX_UPLOAD_MB`
  - `post_max_size = MAX_UPLOAD_MB + 4`
  - `memory_limit = 256M`
  - `max_execution_time = 120`
  - `display_errors = 0`, `log_errors = 1`
  - `date.timezone = APP_TZ`
- ถ้า `PHP_WORKERS > 1` จะตั้ง `PHP_CLI_SERVER_WORKERS` เพื่อให้รับ request พร้อมกันได้

> ไม่ควรแก้คำสั่ง `php -S` เองโดยไม่จำเป็น เพราะ config ของโปรเจกต์ถูกส่งเข้าไปในสคริปต์แล้ว
> และ PID/health check จะทำงานไม่ตรงกัน

---

## 3. ไฟล์ตั้งค่า

ตำแหน่ง:

```text
~/.php-server/config.env
```

แก้ไข:

```bash
nano ~/.php-server/config.env
```

จากนั้น:

```bash
bash scripts/server.sh restart
```

### รายละเอียดค่า

| ค่า | ค่าเริ่มต้น | ความหมาย |
|---|---|---|
| `HOST` | `127.0.0.1` | `127.0.0.1` = เฉพาะเครื่อง, `0.0.0.0` = ทุกอุปกรณ์ใน LAN |
| `PORT` | `8080` | พอร์ตของเซิร์ฟเวอร์ (1–65535) |
| `PHP_WORKERS` | `4` | จำนวน worker (1–32) |
| `APP_TZ` | `Asia/Bangkok` | timezone ที่แสดงใน dashboard |
| `MAX_UPLOAD_MB` | `64` | ขนาดสูงสุดต่อไฟล์ (1–512) |
| `UPLOAD_PIN` | ว่าง | PIN สำหรับปลดล็อกทั้งเว็บ |

ค่าที่ไม่ถูกต้อง:

- `HOST` อื่นนอกจาก `127.0.0.1` / `0.0.0.0` → สคริปต์จะหยุดและแจ้ง error
- `PHP_WORKERS` / `MAX_UPLOAD_MB` นอกช่วง → ใช้ค่าเริ่มต้น
- `PORT` ไม่ใช่ตัวเลข → หยุดและแจ้ง error

### เปลี่ยนพอร์ต

```bash
nano ~/.php-server/config.env
# PORT=8081
bash scripts/server.sh restart
```

เปิด `http://127.0.0.1:8081`

### เปลี่ยนขนาดอัปโหลด

```bash
MAX_UPLOAD_MB=128
```

แล้ว `restart` — ค่านี้ถูกส่งเป็น `php -d upload_max_filesize=...`
ไม่ต้องแก้ `php.ini`

> การอัปโหลดไฟล์ใหญ่มากบนมือถือใช้ RAM และเวลาเพิ่มขึ้น
> แนะนำให้ใช้ `scripts/import.sh` สำหรับไฟล์ใหญ่หรือหลายไฟล์

### เปลี่ยน timezone

```bash
APP_TZ=Asia/Bangkok
```

ใช้ค่า timezone ของ PHP เช่น `Asia/Bangkok`, `UTC`, `Asia/Tokyo`
แล้ว `restart`

---

## 4. หน้า dashboard

เปิด <http://127.0.0.1:8080>

แสดง:

- ที่อยู่เว็บ, PHP version, SAPI, web server
- ระบบปฏิบัติการ/สถาปัตยกรรม, เวลา, timezone
- document root และพื้นที่ดิสก์
- ขนาดอัปโหลดสูงสุดและจำนวนไฟล์ต่อคำขอ
- เช็กลิสต์ความพร้อม (PHP, file_uploads, สิทธิ์โฟลเดอร์, พื้นที่ว่าง)
- การ์ด **เปิดอัตโนมัติเมื่อบูต** — ติดตั้งสคริปต์ boot แล้วหรือยัง, เวลาที่พยายามล่าสุด
  และ 6 บรรทัดท้ายของ `~/.php-server/boot.log` (ใช้ดูว่าหลังรีบูตเซิร์ฟเวอร์ขึ้นจริงไหม)
- รายการไฟล์ที่นำเข้า พร้อมขนาดและเวลา
- คำสั่งจัดการเซิร์ฟเวอร์

ถ้าเปิดจากอุปกรณ์อื่น จะมีแถบเตือนให้ตรวจสอบ PIN

---

## 5. นำเข้าไฟล์ผ่านเว็บ

เปิด <http://127.0.0.1:8080/upload.php>

- เลือกหลายไฟล์ได้ (`files[]`)
- ลากไฟล์มาวางในกรอบได้
- มีแถบความคืบหน้าเมื่อเบราว์เซอร์รองรับ
- ชื่อไฟล์ซ้ำจะถูกเปลี่ยนเป็น `ชื่อ-1.ext`, `ชื่อ-2.ext` อัตโนมัติ
- ไฟล์ที่ขึ้นต้นด้วย `.` จะถูกปฏิเสธ
- ชื่อไฟล์ยาวเกิน 200 ไบต์จะถูกปฏิเสธ
- ไฟล์ถูกเก็บที่ `www/files/`
- ไฟล์ `.php` ที่อัปโหลดจะถูก **execute** เมื่อเปิดผ่าน URL

หน้ารายการไฟล์มีปุ่ม **เปิด** และ **ลบ**
การลบต้องยืนยันและใช้ CSRF token

### ข้อจำกัดขนาด

- ต่อไฟล์: `upload_max_filesize` (มาจาก `MAX_UPLOAD_MB`)
- ต่อคำขอทั้งหมด: `post_max_size` (MAX + 4 MB)
- จำนวนไฟล์ต่อคำขอ: `max_file_uploads` ของ PHP (มักเป็น 20)

ถ้าเกิน `post_max_size` หน้าเว็บจะแจ้งข้อผิดพลาด และแนะนำให้ลดขนาดหรือเพิ่ม config

> ไฟล์ที่อัปโหลดจะไม่ถูก commit ขึ้น Git เพราะ `.gitignore` ละไว้
> แต่ยังอยู่ในเครื่องและเสิร์ฟได้ตามปกติ

---

## 6. นำเข้าจาก Downloads (CLI)

### โหมดมีเมนู

```bash
bash scripts/import.sh
```

สคริปต์จะอ่าน `~/storage/downloads` และแสดงรายการให้เลือก
ถ้ามีมากกว่า 50 รายการ จะแสดง 50 รายการแรก (ระบุ path ตรง ๆ สำหรับไฟล์อื่น)

### โหมดระบุ path

```bash
bash scripts/import.sh ~/storage/downloads/index.php
bash scripts/import.sh ~/storage/downloads/mysite
bash scripts/import.sh ~/storage/downloads/mysite.zip
```

### ตัวเลือก

```bash
--to <ชื่อโฟลเดอร์>   # เก็บใน www/files/<ชื่อโฟลเดอร์>/
--force              # เขียนทับ/รวมไฟล์ที่มีอยู่
--list               # แสดงรายการใน www/files
```

ตัวอย่าง:

```bash
# วาง ZIP ไว้ในโฟลเดอร์ demo
bash scripts/import.sh ~/storage/downloads/mysite.zip --to demo

# เขียนทับเว็บเดิม
bash scripts/import.sh ~/storage/downloads/mysite --to demo --force

# ดูไฟล์ทั้งหมด
bash scripts/import.sh --list
```

### ปลายทางเริ่มต้น

| ต้นทาง | ปลายทาง |
|---|---|
| ไฟล์ `index.php` | `www/files/index.php` |
| โฟลเดอร์ `mysite` | `www/files/mysite/...` |
| `mysite.zip` | `www/files/mysite/...` |
| ไฟล์ + `--to demo` | `www/files/demo/<ชื่อไฟล์>` |
| โฟลเดอร์ + `--to demo` | `www/files/demo/...` |
| ZIP + `--to demo` | แตกไฟล์ลง `www/files/demo/...` |

- ถ้าปลายทางมีไฟล์อยู่แล้วและไม่ได้ใส่ `--force` สคริปต์จะหยุดและแจ้งเตือน
- ZIP ต้องมีคำสั่ง `unzip` (ติดตั้งใน setup)
- ZIP ที่มีโฟลเดอร์ชั้นในจะแตกตามโครงสร้างเดิม
- อย่าแตก ZIP จากแหล่งที่ไม่น่าเชื่อถือ เพราะอาจมี path แปลก ๆ (zip slip)

หลัง import สคริปต์จะพิมพ์ path จริงและ URL ให้ เช่น:

```text
✓ นำเข้าเรียบร้อย
  ที่เก็บ: /data/data/com.termux/files/home/android-php-server/www/files/mysite
  เปิด:   http://127.0.0.1:8080/files/mysite/
```

ถ้าเซิร์ฟเวอร์ยังไม่ทำงาน สคริปต์จะเตือนให้ `start` ก่อน

---

## 7. PIN และการปกป้องทั้งเว็บ

### เปิดใช้ PIN

```bash
nano ~/.php-server/config.env
```

```bash
UPLOAD_PIN=รหัสที่คาดเดายาก
```

```bash
bash scripts/server.sh restart
```

เมื่อตั้ง PIN:

- ทุกหน้า (dashboard, ไฟล์, PHP ที่นำเข้า) จะถูก redirect ไปหน้า login
- ยกเว้นหน้า login (`upload.php`) และ assets ที่จำเป็นสำหรับแสดงหน้า
- `/healthz` ยังเปิดได้ (ใช้ตรวจสถานะ)
- เมื่อ login สำเร็จจะได้ session cookie ชื่อ `PHPSRV`
- session ถูกผูกกับ SHA-256 ของ PIN ปัจจุบัน → เปลี่ยน PIN แล้ว session เก่าใช้ไม่ได้
- ปุ่ม **ล็อกเอาต์** อยู่ในการ์ดความปลอดภัยของหน้า `upload.php`

### ปิด PIN

ตั้ง `UPLOAD_PIN=` (ว่าง) แล้ว `restart`
ระบบจะเปิดทุกหน้าโดยไม่ต้อง login

### ลืม PIN

แก้ config ให้เป็นค่าว่างหรือตั้งใหม่ แล้ว `restart`
ไม่มีการเก็บ PIN แบบเข้ารหัสในฐานข้อมูล — PIN อยู่ในไฟล์ config ที่สิทธิ์ 600

### หมายเหตุด้านความปลอดภัยของ PIN

- เป็น PIN แบบ session ไม่ใช่ระบบ user account
- ไม่มี rate limit ที่ซับซ้อน มีแค่หน่วงเวลาเมื่อใส่ผิด
- ไม่มีการเข้ารหัสการรับส่ง (HTTP) — PIN ถูกส่งใน LAN แบบไม่เข้ารหัส
- ใช้ได้เหมาะกับวง Wi-Fi ที่ไว้ใจได้เท่านั้น

---

## 8. เปิดใช้จากเครื่องอื่นใน Wi-Fi

1. แก้ config:

```bash
HOST=0.0.0.0
UPLOAD_PIN=รหัสของคุณ
```

2. `bash scripts/server.sh restart`

3. ดู IP ของมือถือ:
   - Settings → About phone → Status → IP address
   - หรือ Wi-Fi → รายละเอียดเครือข่าย

4. เปิดจากคอม/แท็บเล็ต:

```text
http://192.168.x.x:8080
```

### ถ้าเข้าไม่ได้

- ตรวจว่าอยู่ Wi-Fi เดียวกัน
- ปิด VPN ชั่วคราว
- router อาจเปิด **AP isolation** (client isolation) — ปิดฟีเจอร์นี้หรือใช้ hotspot ของมือถือ
- ตรวจ `bash scripts/server.sh status` ว่า health ปกติ
- ตรวจ `HOST=0.0.0.0` และ restart แล้วจริง
- Android firewall/แอป VPN อาจบล็อกพอร์ต

### คำเตือน

- อย่า port-forward จาก router
- ไม่มี HTTPS
- เมื่อไม่ได้ตั้ง PIN ทุกคนในวง Wi-Fi เปิดไฟล์ทั้งหมดได้
- ไฟล์ PHP ทำงานด้วยสิทธิ์ของ Termux (ไม่ใช่ root แต่เข้าถึงไฟล์ในบ้าน Termux ได้)

---

## 9. ดู log และแก้ PHP error

```bash
bash scripts/server.sh logs
```

ไฟล์ log:

```bash
tail -n 50 ~/.php-server/server.log
tail -n 50 ~/.php-server/php-error.log
tail -n 50 ~/.php-server/boot.log
```

- `server.log` — output ของ PHP built-in server (request/error)
- `php-error.log` — warning/fatal error ของ PHP (เพราะ `display_errors=0`)
- `boot.log` — เฉพาะตอนเปิดอัตโนมัติผ่าน Termux:Boot

ตรวจ syntax ของไฟล์ PHP:

```bash
php -l www/index.php
php -l www/upload.php
php -l router.php
```

ทดสอบ health:

```bash
curl -i http://127.0.0.1:8080/healthz
```

ควรได้ `HTTP/1.1 200 OK` และ body `ok`

---

## 10. เพิ่มเว็บของคุณเอง

### วิธีที่ปลอดภัย: วางใน files/

1. นำเข้าโฟลเดอร์เว็บด้วย `import.sh --to myapp`
2. ตรวจว่ามี `www/files/myapp/index.php`
3. เปิด `http://127.0.0.1:8080/files/myapp/`

### วิธีแทนหน้าแรก

สำรอง dashboard ก่อน:

```bash
cp www/index.php www/index.dashboard.php
```

แล้วแก้ `www/index.php` หรือคัดลอกเว็บของคุณมาวางใน `www/`
(ไฟล์ `.php` ทุกไฟล์จะถูก execute)

### Framework / routing

โปรเจกต์นี้ใช้ `router.php` ของตัวเอง ซึ่งทำหน้าที่ประมาณ static file server
หากต้องการ front controller ของ framework (เช่นส่งทุก request ไป `index.php`)
ต้องแก้ `router.php` เพิ่มเอง — อย่าใช้ router ของโปรเจกต์นี้ร่วมกับ framework
โดยไม่ตรวจสอบเส้นทางก่อน

---

## 11. สำรองและกู้คืน

### สำรอง

```bash
cd ~/android-php-server
tar -czf ~/storage/downloads/php-server-backup.tar.gz www ~/.php-server/config.env
```

จะได้ไฟล์ใน Downloads ที่เก็บทั้งเว็บ ไฟล์นำเข้า และ config

### กู้คืน

```bash
cd ~/android-php-server
tar -xzf ~/storage/downloads/php-server-backup.tar.gz -C ~/
bash scripts/server.sh restart
```

ตรวจ path ใน archive ก่อนแตกไฟล์ทุกครั้ง

### รีเซ็ตเฉพาะ config

```bash
bash scripts/server.sh stop
rm ~/.php-server/config.env
bash scripts/setup.sh
bash scripts/server.sh start
```

ไฟล์ใน `www/` และ `www/files/` จะยังอยู่

---

## 12. เปิดอัตโนมัติและการหยุดเมื่อปิดจอ

Termux:Boot + wake lock ช่วยให้เซิร์ฟเวอร์ทำงานต่อได้ แต่ Android อาจ kill process
เมื่อ RAM ต่ำหรือ battery optimization เข้มงวด

แนวทาง:

- Settings → Apps → Termux → Battery → **Unrestricted**
- ใช้คำสั่ง `termux-wake-lock` (สคริปต์ start/stop จะเรียกให้อัตโนมัติถ้ามีคำสั่งนี้ในเครื่อง)
- อย่าปัด Termux ออกจาก recent apps
- ใช้เครื่องชาร์จ/แบตเตอรี่เพียงพอ

ปิด wake lock เมื่อไม่ใช้:

```bash
bash scripts/server.sh stop
```

คำสั่ง stop จะเรียก `termux-wake-unlock` ให้

---

## 13. ความปลอดภัยโดยสรุป

- ใช้เฉพาะในเครื่องหรือ LAN ที่ไว้ใจได้
- ตั้ง PIN ทุกครั้งที่เปิด `HOST=0.0.0.0`
- อย่าใช้รหัส PIN ซ้ำกับบัญชีอื่น
- อย่าอัปโหลด PHP ที่ไม่รู้ที่มา — โค้ดนั้นรันได้จริง
- ตรวจไฟล์ก่อนนำเข้า และสำรองข้อมูลเสมอ
- ไม่ควรใช้ PHP built-in server เป็น public web server
- อัปเดต Termux/แพ็กเกจเป็นระยะ:

```bash
pkg update -y && pkg upgrade -y
```

---

## 14. ตั้งค่าจากหน้าเว็บ (พอร์ต / PIN)

เปิด <http://127.0.0.1:8080/settings.php> หรือกดเมนู **ตั้งค่า**

- แก้ **พอร์ต** (1024–65535), **PIN** และ **การเข้าถึง** ได้จากฟอร์ม
- PIN ใหม่: เว้นว่าง = คงค่าเดิม, ติ๊ก "ปิด PIN" = ปิดการป้องกันทั้งเว็บ
- **โหมด LAN (0.0.0.0)** เปิดได้เฉพาะเมื่อมี PIN — ถ้ายังไม่มี PIN ระบบจะไม่ยอมบันทึก

เมื่อกดบันทึก ระบบจะ:

1. สำรอง `config.env` ไว้ที่ `~/.php-server/config.env.bak`
2. เขียนค่าใหม่แบบ atomic (คงคอมเมนต์และค่าอื่นไว้)
3. รีสตาร์ตเซิร์ฟเวอร์ผ่าน `scripts/apply-settings.sh`
4. ถ้าพอร์ตใหม่ไม่ตอบ `/healthz` ภายใน ~25 วินาที จะ **ย้อนกลับค่าเดิมให้อัตโนมัติ**

ผลลัพธ์ล่าสุดแสดงบนหน้า settings และบันทึกไว้ที่:

```bash
tail -n 40 ~/.php-server/settings.log
cat ~/.php-server/settings-result.txt
```

ถ้าย้อนกลับไม่สำเร็จ ให้สั่งเองใน Termux:

```bash
bash scripts/server.sh restart
```

> เมื่อเปลี่ยน PIN ทุก session เดิมจะใช้ไม่ได้ (ระบบผูก session กับ PIN) ต้องล็อกอินใหม่

หลังเปิดโหมด LAN ให้ดู IP ของเครื่องใน Settings → About phone → Status หรือรันใน Termux:

```bash
ip -4 addr show wlan0 | grep inet
```

แล้วเปิดจากเครื่องอื่นใน Wi-Fi เดียวกันที่ `http://<IP-ของมือถือ>:8080` (ต้องใส่ PIN)

---

## 15. ดู log ผ่านเว็บ

เปิด <http://127.0.0.1:8080/logs.php> หรือกดเมนู **บันทึก**

ดูได้ 4 ไฟล์ (200 บรรทัดล่าสุดแต่ละไฟล์ ไม่ต้องเข้า Termux):

| แท็บ | ไฟล์ | ใช้ดูอะไร |
|---|---|---|
| เซิร์ฟเวอร์ | `~/.php-server/server.log` | คำขอที่เข้ามา, สถานะ HTTP |
| PHP | `~/.php-server/php-error.log` | warning / deprecated / fatal error |
| Autostart | `~/.php-server/boot.log` | ผลการเปิดเซิร์ฟเวอร์หลังรีบูต |
| การตั้งค่า | `~/.php-server/settings.log` | ผลการรีสตาร์ตหลังแก้ค่าจากหน้า settings |

ไฟล์ใหญ่จะอ่านเฉพาะ 512 KB ท้ายไฟล์ และจำกัดที่ 200 บรรทัด เพื่อไม่ให้หน้าเว็บหนัก

---

## 16. ตรวจ secrets ก่อน push

ก่อน push ขึ้น GitHub ให้ตรวจว่ามี key/secret หลุดเข้าไฟล์ไหม:

```bash
bash scripts/check-secrets.sh          # ตรวจทั้งโปรเจกต์
bash scripts/check-secrets.sh --staged # ตรวจเฉพาะไฟล์ที่ stage ไว้
```

ติดตั้งเป็น git hook เพื่อบล็อกอัตโนมัติทุกครั้งที่ push:

```bash
bash scripts/check-secrets.sh --install
```

สคริปต์ตรวจ:

- Private key block, AWS access key, GitHub/Slack/Stripe/Google token
- ค่าที่หน้าตาเป็น `api_key`, `api_secret`, `password`, `access_token` ที่ตามด้วยค่ายาว ๆ
- สตริง hex 64 ตัว (เช่นคีย์ 32 ไบต์)

ถ้าพบ จะแสดง `ไฟล์:บรรทัด` และ **ไม่พิมพ์ค่าลับออกมา** พร้อมคืนค่า exit code 1

> ไฟล์อย่าง `nh.php` และ `nh.config.php` อยู่ใน `.gitignore` แล้ว จึงไม่ถูกสแกน/commit
