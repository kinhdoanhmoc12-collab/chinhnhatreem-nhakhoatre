#!/usr/bin/env bash
# Cài đặt / cập nhật website chinhnhatreem.com trên VPS Ubuntu hoặc Debian.
# Cách chạy (với quyền root):  bash install.sh
# Chạy lại lần nữa để cập nhật web khi có bản mới, dữ liệu lịch hẹn được giữ nguyên.
set -euo pipefail

DOMAIN="chinhnhatreem.com"
EMAIL="${CERT_EMAIL:-cskh.nhakhoatre@gmail.com}"    # email nhận thông báo gia hạn SSL
WEBROOT="/var/www/${DOMAIN}"
DATA="/var/www/chinhnhatreem-data"
CONF="/etc/nginx/sites-available/${DOMAIN}"
HERE="$(cd "$(dirname "$0")" && pwd)"

xanh(){ printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
vang(){ printf '\033[1;33m[!] %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Cần chạy bằng tài khoản root."; exit 1; }
command -v apt-get >/dev/null || { echo "Script này dành cho Ubuntu/Debian. Hệ điều hành hiện tại chưa hỗ trợ."; exit 1; }
# VPS đang cài CloudPanel: thông báo và tiếp tục cập nhật
if command -v clpctl >/dev/null 2>&1 || [ -d /home/clp ]; then
  vang "Phát hiện VPS có CloudPanel: tiếp tục cài đặt/cập nhật website chinhnhatreem.com..."
fi
[ -f "$HERE/public/index.html" ] || { echo "Không thấy thư mục public/ cạnh install.sh. Hãy giải nén đủ file zip."; exit 1; }

xanh "1/7 Cài phần mềm (Nginx, PHP, Certbot)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx php-fpm php-mbstring php-sqlite3 php-gd php-xml php-intl php-curl certbot ufw curl dnsutils rsync
systemctl disable --now apache2 >/dev/null 2>&1 || true   # tránh tranh cổng 80 với Nginx
PHPSOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
if [ -z "$PHPSOCK" ]; then
  systemctl restart "$(systemctl list-unit-files | awk '/php.*-fpm.service/{print $1; exit}')" || true
  sleep 2; PHPSOCK="$(ls /run/php/php*-fpm.sock | head -1)"
fi
echo "PHP-FPM: $PHPSOCK"

xanh "2/7 Chép website vào $WEBROOT"
mkdir -p "$WEBROOT" "$DATA"
rsync -a --delete --exclude '/_gen/' "$HERE/public/" "$WEBROOT/public/"
rsync -a --delete "$HERE/app/" "$WEBROOT/app/"
chown -R root:root "$WEBROOT/public" "$WEBROOT/app"
find "$WEBROOT/public" "$WEBROOT/app" -path "$WEBROOT/public/_gen" -prune -o -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +
# Thư mục trang quản trị được phép ghi: trang xuất ra và ảnh tải lên
mkdir -p "$WEBROOT/public/_gen/bai-viet" "$WEBROOT/public/_gen/uploads"
chown -R www-data:www-data "$WEBROOT/public/_gen"
chown www-data:www-data "$DATA"; chmod 750 "$DATA"

xanh "3/7 Tài khoản quản trị (/quan-tri)"
rm -f /etc/nginx/.htpasswd-chinhnhatreem
PASS="$(runuser -u www-data -- php "$WEBROOT/app/cli.php" create-admin admin)"
if [ "$PASS" = "EXISTS" ]; then
  PASS="(giữ nguyên mật khẩu cũ, xem /root/mat-khau-quan-tri.txt)"
else
  echo "Tài khoản: admin / Mật khẩu: $PASS" > /root/mat-khau-quan-tri.txt; chmod 600 /root/mat-khau-quan-tri.txt
fi

xanh "4/7 Kiểm tra tên miền đã trỏ về VPS chưa"
IP="$(curl -4 -s --max-time 8 https://api.ipify.org || hostname -I | awk '{print $1}')"
for h in "$DOMAIN" "www.$DOMAIN"; do
  R="$(dig +short A "$h" @1.1.1.1 | tail -1)"
  if [ "$R" = "$IP" ]; then echo "$h -> $R  OK"; else vang "$h đang trỏ về '$R', VPS là '$IP'. SSL sẽ lỗi nếu chưa trỏ đúng."; fi
done

xanh "5/7 Lấy chứng chỉ SSL miễn phí (Let's Encrypt)"
mkdir -p /var/www/letsencrypt
if [ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
  cat > "$CONF" <<NGX
server {
  listen 80;
  server_name $DOMAIN www.$DOMAIN;
  location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
  location / { root $WEBROOT/public; }
}
NGX
  ln -sf "$CONF" /etc/nginx/sites-enabled/$DOMAIN
  rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx
  certbot certonly --webroot -w /var/www/letsencrypt -d "$DOMAIN" -d "www.$DOMAIN" \
    --agree-tos -m "$EMAIL" --non-interactive --no-eff-email
fi
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

xanh "6/7 Cấu hình Nginx (HTTPS, chuyển hướng 301, nén, cache, bảo mật)"
cat > /etc/nginx/conf.d/chinhnhatreem-zones.conf <<'NGX'
limit_req_zone $binary_remote_addr zone=nkt_forms:10m rate=20r/m;
limit_req_zone $binary_remote_addr zone=nkt_admin:10m rate=1200r/m;
NGX
sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__ROOT__#$WEBROOT/public#g" \
    -e "s#__PHPSOCK__#$PHPSOCK#g" \
    "$HERE/nginx-site.conf" > "$CONF"
# VPS không bật IPv6 thì bỏ các dòng listen [::]
if [ ! -s /proc/net/if_inet6 ]; then sed -i 's/ listen \[::\]:[0-9]*[^;]*;//' "$CONF"; fi
ln -sf "$CONF" /etc/nginx/sites-enabled/$DOMAIN
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl reload nginx
# Xuất lại các trang bài viết / SEO theo mẫu mới nhất
runuser -u www-data -- php "$WEBROOT/app/cli.php" publish >/dev/null

xanh "7/7 Tường lửa (chỉ mở SSH, HTTP, HTTPS)"
ufw allow OpenSSH >/dev/null; ufw allow 22/tcp >/dev/null; ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
ufw status | head -8

xanh "XONG"
echo "Website:            https://$DOMAIN"
echo "Trang quản trị:     https://$DOMAIN/quan-tri/   (tài khoản: admin, mật khẩu tạm: $PASS)"
echo "                    Lần đầu đăng nhập sẽ được yêu cầu đổi mật khẩu."
echo "Dữ liệu lịch hẹn:   $DATA"
echo "SSL tự gia hạn mỗi 90 ngày (certbot.timer)."
