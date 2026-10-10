#!/usr/bin/env bash
# Cài đặt / cập nhật website chinhnhatreem.com trên VPS Ubuntu hoặc Debian.
# Cách chạy (với quyền root):  bash install.sh
# Chạy lại lần nữa để cập nhật web khi có bản mới, dữ liệu lịch hẹn được giữ nguyên.
set -euo pipefail

DOMAIN="chinhnhatreem.com"
EMAIL="${CERT_EMAIL:-cskh.nhakhoatre@gmail.com}"    # email nhận thông báo gia hạn SSL
WEBROOT="/var/www/${DOMAIN}"
DATA="/var/www/chinhnhatreem-data"
HTPASS="/etc/nginx/.htpasswd-chinhnhatreem"
CONF="/etc/nginx/sites-available/${DOMAIN}"
HERE="$(cd "$(dirname "$0")" && pwd)"

xanh(){ printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
vang(){ printf '\033[1;33m[!] %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Cần chạy bằng tài khoản root."; exit 1; }
command -v apt-get >/dev/null || { echo "Script này dành cho Ubuntu/Debian. Hệ điều hành hiện tại chưa hỗ trợ."; exit 1; }
[ -f "$HERE/public/index.html" ] || { echo "Không thấy thư mục public/ cạnh install.sh. Hãy giải nén đủ file zip."; exit 1; }

xanh "1/7 Cài phần mềm (Nginx, PHP, Certbot)"
export DEBIAN_FRONTEND=noninteractive
mkdir -p /etc/nginx/conf.d
echo "server_names_hash_bucket_size 128;" > /etc/nginx/conf.d/server_names_hash.conf
apt-get update -y
apt-get install -y nginx php-fpm php-mbstring certbot apache2-utils ufw curl dnsutils rsync
systemctl disable --now apache2 >/dev/null 2>&1 || true   # tránh tranh cổng 80 với Nginx
PHPSOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
if [ -z "$PHPSOCK" ]; then
  systemctl restart "$(systemctl list-unit-files | awk '/php.*-fpm.service/{print $1; exit}')" || true
  sleep 2; PHPSOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || true)"
fi
[ -n "$PHPSOCK" ] || { echo "Không tìm thấy socket PHP-FPM. Kiểm tra service php-fpm."; exit 1; }
echo "PHP-FPM: $PHPSOCK"

xanh "2/7 Chép website vào $WEBROOT"
mkdir -p "$WEBROOT" "$DATA"
rsync -a --delete "$HERE/public/" "$WEBROOT/public/"
chown -R root:root "$WEBROOT"; find "$WEBROOT" -type d -exec chmod 755 {} +; find "$WEBROOT" -type f -exec chmod 644 {} +
chown www-data:www-data "$DATA"; chmod 750 "$DATA"

xanh "3/7 Mật khẩu trang quản lý lịch hẹn (/quan-ly)"
if [ ! -f "$HTPASS" ]; then
  PASS="$(head -c 64 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 14)"
  htpasswd -bc "$HTPASS" admin "$PASS" >/dev/null
  chown root:www-data "$HTPASS"; chmod 640 "$HTPASS"
  echo "$PASS" > /root/mat-khau-quan-ly.txt; chmod 600 /root/mat-khau-quan-ly.txt
else
  PASS="(giữ nguyên mật khẩu cũ, xem /root/mat-khau-quan-ly.txt)"
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
limit_req_zone $binary_remote_addr zone=nkt_forms:10m rate=6r/m;
NGX
sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__ROOT__#$WEBROOT/public#g" \
    -e "s#__PHPSOCK__#$PHPSOCK#g" -e "s#__HTPASS__#$HTPASS#g" \
    "$HERE/nginx-site.conf" > "$CONF"
# VPS không bật IPv6 thì bỏ các dòng listen [::]
if [ ! -s /proc/net/if_inet6 ]; then sed -i 's/ listen \[::\]:[0-9]*[^;]*;//' "$CONF"; fi
ln -sf "$CONF" /etc/nginx/sites-enabled/$DOMAIN
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl reload nginx

xanh "7/7 Tường lửa (chỉ mở SSH, HTTP, HTTPS)"
ufw allow OpenSSH >/dev/null; ufw allow 22/tcp >/dev/null; ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null
ufw status | head -8

xanh "XONG"
echo "Website:            https://$DOMAIN"
echo "Trang quản lý:      https://$DOMAIN/quan-ly/   (tài khoản: admin, mật khẩu: $PASS)"
echo "Dữ liệu lịch hẹn:   $DATA"
echo "SSL tự gia hạn mỗi 90 ngày (certbot.timer)."
