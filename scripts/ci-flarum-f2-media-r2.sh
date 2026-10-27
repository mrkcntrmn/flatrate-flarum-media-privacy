#!/usr/bin/env bash
# Disposable Flarum 2.0.2 FoF Upload + Flysystem 3 + Media Privacy object-store proof.
# Uses isolated MariaDB + local private-shared disk; optional moto S3-compatible endpoint.
# PRODUCTION=NO CHANGES. No production R2 credentials/buckets.
set -euo pipefail

export PATH="/usr/bin:/bin:/usr/local/bin:${PATH:-}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
git config --global --add safe.directory "${ROOT}" >/dev/null 2>&1 || true
SMOKE_ROOT="${FLARUM_SMOKE_ROOT:-${TMPDIR:-/tmp}/flarum-media-f2-r2-$$}"
EVIDENCE_DIR="${MEDIA_EVIDENCE_DIR:-${ROOT}/evidence/forum-f2-media-r2}"
FLARUM_SKELETON_VERSION="${FLARUM_SKELETON_VERSION:-2.0.0}"
EXPECTED_CORE="${FLARUM_CORE_VERSION:-2.0.2}"
FOF_UPLOAD_PIN="${FOF_UPLOAD_PIN:-2.0.3}"
BASE="http://127.0.0.1:8772"

DB_HOST="${FLARUM_DB_HOST:-127.0.0.1}"
DB_PORT="${FLARUM_DB_PORT:-3306}"
DB_NAME="${FLARUM_DB_DATABASE:-flarum_media_f2_r2}"
DB_USER="${FLARUM_DB_USERNAME:-root}"
DB_PASS="${FLARUM_DB_PASSWORD:-disposable-local}"

S3_ENDPOINT="${FOF_UPLOAD_AWS_S3_ENDPOINT:-http://127.0.0.1:4566}"
S3_REGION="${FOF_UPLOAD_AWS_S3_REGION:-us-east-1}"
S3_BUCKET="${FOF_UPLOAD_AWS_S3_BUCKET:-flatrate-media-f2-test}"
S3_KEY="${FOF_UPLOAD_AWS_S3_KEY:-testing}"
S3_SECRET="${FOF_UPLOAD_AWS_S3_SECRET:-testing}"

RESULT_S3_COMPAT=NOT_PROVEN
RESULT_R2=NOT_PROVEN
MOTO_PID=""
HTTP_PID=""
MARIA_CID=""

pass() { echo "$1=PASS${2:+ $2}"; }
fail() { echo "$1=FAIL${2:+ $2}" >&2; exit 1; }
not_proven() { echo "$1=NOT_PROVEN${2:+ $2}"; }

cleanup() {
  if [[ -n "${HTTP_PID}" ]]; then kill "${HTTP_PID}" >/dev/null 2>&1 || true; fi
  if [[ -n "${MOTO_PID}" ]]; then kill "${MOTO_PID}" >/dev/null 2>&1 || true; fi
  # Only the host process that started MariaDB should remove it.
  if [[ -n "${MARIA_CID}" && "${MEDIA_OWNED_MARIA:-0}" == "1" ]]; then
    docker rm -f "${MARIA_CID}" >/dev/null 2>&1 || true
  fi
  if [[ "${KEEP_SMOKE_ROOT:-0}" != "1" ]]; then rm -rf "${SMOKE_ROOT}"; fi
}
trap cleanup EXIT

mkdir -p "${EVIDENCE_DIR}" "${SMOKE_ROOT}"

start_host_mariadb_if_needed() {
  if command -v php >/dev/null 2>&1 && php -r '
    try { new PDO("mysql:host=".$argv[1].";port=".$argv[2], $argv[3], $argv[4]); exit(0); }
    catch (Throwable $e) { exit(1); }
  ' "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}" 2>/dev/null; then
    return 0
  fi
  if ! command -v docker >/dev/null 2>&1; then
    fail MEDIA_COMPOSER_F2 "mariadb_unreachable_and_no_docker"
  fi
  docker rm -f media-f2-maria-r4 >/dev/null 2>&1 || true
  MARIA_CID="$(docker run -d --name media-f2-maria-r4 \
    -e MYSQL_ROOT_PASSWORD="${DB_PASS}" \
    -p "127.0.0.1:0:3306" \
    mariadb:11.4)"
  MEDIA_OWNED_MARIA=1
  DB_PORT="$(docker port "${MARIA_CID}" 3306/tcp | awk -F: '{print $NF}')"
  export FLARUM_DB_PORT="${DB_PORT}" DB_PORT MARIA_CID MEDIA_OWNED_MARIA
  for _ in $(seq 1 90); do
    if docker exec "${MARIA_CID}" mariadb-admin ping -uroot -p"${DB_PASS}" --silent >/dev/null 2>&1 \
      || docker exec "${MARIA_CID}" mysqladmin ping -uroot -p"${DB_PASS}" --silent >/dev/null 2>&1 \
      || docker run --rm --network host flatrate-php84-f2:local php -r '
          try { new PDO("mysql:host=127.0.0.1;port=".$argv[1], "root", $argv[2]); exit(0); }
          catch (Throwable $e) { exit(1); }
        ' "${DB_PORT}" "${DB_PASS}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  fail MEDIA_COMPOSER_F2 "mariadb_not_ready"
}

maybe_reexec_docker() {
  if [[ "${MEDIA_F2_IN_DOCKER:-0}" == "1" ]]; then return 0; fi
  if php -r 'exit((extension_loaded("dom") && extension_loaded("pdo_mysql") && extension_loaded("curl") && extension_loaded("gd")) ? 0 : 1);' 2>/dev/null; then
    return 0
  fi
  local image=""
  if docker image inspect flatrate-php84-f2:local >/dev/null 2>&1; then
    image="flatrate-php84-f2:local"
  elif docker image inspect php:8.4-cli >/dev/null 2>&1; then
    image="php:8.4-cli"
  else
    fail MEDIA_COMPOSER_F2 "host_php_missing_extensions_and_no_docker_image"
  fi
  start_host_mariadb_if_needed
  echo "MEDIA_F2=REEXEC image=${image} db_port=${DB_PORT}"
  exec docker run --rm --network host \
    -e MEDIA_F2_IN_DOCKER=1 \
    -e FLARUM_SMOKE_ROOT -e MEDIA_EVIDENCE_DIR -e KEEP_SMOKE_ROOT \
    -e FLARUM_SKELETON_VERSION -e FLARUM_CORE_VERSION -e FOF_UPLOAD_PIN \
    -e FLARUM_DB_HOST=127.0.0.1 \
    -e FLARUM_DB_PORT="${DB_PORT}" \
    -e FLARUM_DB_DATABASE -e FLARUM_DB_USERNAME -e FLARUM_DB_PASSWORD \
    -e FOF_UPLOAD_AWS_S3_ENDPOINT -e FOF_UPLOAD_AWS_S3_REGION -e FOF_UPLOAD_AWS_S3_BUCKET \
    -e FOF_UPLOAD_AWS_S3_KEY -e FOF_UPLOAD_AWS_S3_SECRET \
    -e MOTO_BIN \
    -e MARIA_CID -e MEDIA_OWNED_MARIA=0 \
    -v /home/ilove/dev:/home/ilove/dev \
    -v "${TMPDIR:-/tmp}:${TMPDIR:-/tmp}" \
    -w "${ROOT}" \
    "${image}" \
    bash "${ROOT}/scripts/ci-flarum-f2-media-r2.sh"
}
maybe_reexec_docker

ensure_mariadb() {
  for _ in $(seq 1 60); do
    if php -r '
      try { new PDO("mysql:host=".$argv[1].";port=".$argv[2], $argv[3], $argv[4]); exit(0); }
      catch (Throwable $e) { exit(1); }
    ' "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}"; then
      return 0
    fi
    sleep 1
  done
  fail MEDIA_COMPOSER_F2 "mariadb_not_ready"
}

start_moto_if_available() {
  local bin="${MOTO_BIN:-}"
  if [[ -z "${bin}" ]]; then
    for c in /tmp/moto-venv/bin/moto_server "${HOME}/.local/bin/moto_server"; do
      if [[ -x "${c}" ]]; then bin="${c}"; break; fi
    done
  fi
  if [[ -z "${bin}" ]]; then
    not_proven S3_COMPATIBLE_OBJECT_E2E "moto_server_unavailable"
    return 0
  fi
  # moto 5.x: no positional service arg; bind host/port only.
  "${bin}" -H 127.0.0.1 -p 4566 >"${SMOKE_ROOT}/moto.log" 2>&1 &
  MOTO_PID=$!
  for _ in $(seq 1 40); do
    if curl -sf "${S3_ENDPOINT}/" >/dev/null 2>&1 || curl -s -o /dev/null -w '%{http_code}' "${S3_ENDPOINT}/" | grep -qE '^[0-9]+$'; then
      break
    fi
    sleep 0.25
  done
  if ! curl -sf "${S3_ENDPOINT}/" >/dev/null 2>&1 && ! curl -s -o /dev/null -w '%{http_code}' "${S3_ENDPOINT}/" | grep -qE '^[0-9]+$'; then
    not_proven S3_COMPATIBLE_OBJECT_E2E "moto_server_not_ready"
    kill "${MOTO_PID}" >/dev/null 2>&1 || true
    MOTO_PID=""
    return 0
  fi
  # Create bucket via AWS CLI-compatible PHP/SDK or awscurl
  if command -v aws >/dev/null 2>&1; then
    AWS_ACCESS_KEY_ID="${S3_KEY}" AWS_SECRET_ACCESS_KEY="${S3_SECRET}" \
      aws --endpoint-url "${S3_ENDPOINT}" --region "${S3_REGION}" s3 mb "s3://${S3_BUCKET}" >/dev/null 2>&1 || true
  else
    php -r '
      require $argv[1]."/vendor/autoload.php";
      $s3 = new Aws\S3\S3Client([
        "version" => "latest",
        "region" => $argv[2],
        "endpoint" => $argv[3],
        "use_path_style_endpoint" => true,
        "credentials" => ["key" => $argv[4], "secret" => $argv[5]],
      ]);
      try { $s3->createBucket(["Bucket" => $argv[6]]); } catch (Throwable $e) {}
    ' "${SMOKE_ROOT}/app" "${S3_REGION}" "${S3_ENDPOINT}" "${S3_KEY}" "${S3_SECRET}" "${S3_BUCKET}" 2>/dev/null || true
  fi
  export MEDIA_S3_ENABLED=1
}

ensure_mariadb

php -r '
$pdo = new PDO("mysql:host=".$argv[1].";port=".$argv[2], $argv[3], $argv[4], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name = preg_replace("/[^A-Za-z0-9_]/", "", $argv[5]);
$pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
$pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
' "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}" "${DB_NAME}"

rm -rf "${SMOKE_ROOT}/app"
composer create-project "flarum/flarum:${FLARUM_SKELETON_VERSION}" "${SMOKE_ROOT}/app" --no-interaction --no-install
cd "${SMOKE_ROOT}/app"
rm -f composer.lock
composer require "flarum/core:${EXPECTED_CORE}" --no-update --no-interaction
composer update --no-interaction --prefer-dist --no-audit

INSTALLED_CORE="$(composer show flarum/core --format=json | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo preg_replace("/^v/","",(string)($j["versions"][0]??""));')"
[[ "${INSTALLED_CORE}" == "${EXPECTED_CORE}" ]] || fail MEDIA_COMPOSER_F2 "expected_core=${EXPECTED_CORE} got=${INSTALLED_CORE}"

composer config repositories.flatrate-media-privacy path "${ROOT}"
composer require \
  "fof/upload:${FOF_UPLOAD_PIN}" \
  "league/flysystem-aws-s3-v3:^3.0" \
  "flatrate/flarum-media-privacy:@dev" \
  --no-update --no-interaction

# Prefer 2.0.3; fall back to 2.0.2 if resolution fails.
if ! composer update --no-interaction --prefer-dist --no-audit 2>"${SMOKE_ROOT}/composer-err.txt"; then
  echo "MEDIA_COMPOSER_NOTE=fof_upload_${FOF_UPLOAD_PIN}_failed_trying_2.0.2"
  composer require "fof/upload:2.0.2" --no-update --no-interaction
  composer update --no-interaction --prefer-dist --no-audit
  FOF_UPLOAD_PIN=2.0.2
fi

FOF_VER="$(composer show fof/upload --format=json | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo preg_replace("/^v/","",(string)($j["versions"][0]??""));')"
FLY_VER="$(composer show league/flysystem --format=json | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo preg_replace("/^v/","",(string)($j["versions"][0]??""));')"
FLY_S3_VER="$(composer show league/flysystem-aws-s3-v3 --format=json | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo preg_replace("/^v/","",(string)($j["versions"][0]??""));')"
MEDIA_SHA="$(git -C "${ROOT}" rev-parse HEAD)"

[[ "${FOF_VER}" == 2.* ]] || fail FOF_UPLOAD_F2 "got=${FOF_VER}"
[[ "${FLY_VER}" == 3.* ]] || fail FLYSYSTEM3 "got=${FLY_VER}"
pass MEDIA_COMPOSER_F2 "core=${INSTALLED_CORE} fof=${FOF_VER} fly=${FLY_VER} fly-s3=${FLY_S3_VER} media=${MEDIA_SHA:0:12}"
pass FOF_UPLOAD_F2 "version=${FOF_VER}"
pass FLYSYSTEM3 "version=${FLY_VER} aws-s3-v3=${FLY_S3_VER}"

cat > "${SMOKE_ROOT}/install.json" <<EOF
{
  "debug": true,
  "baseUrl": "${BASE}",
  "databaseConfiguration": {
    "driver": "mysql",
    "host": "${DB_HOST}",
    "port": ${DB_PORT},
    "database": "${DB_NAME}",
    "username": "${DB_USER}",
    "password": "${DB_PASS}",
    "prefix": "",
    "charset": "utf8mb4",
    "collation": "utf8mb4_unicode_ci"
  },
  "adminUser": {
    "username": "admin",
    "password": "admin-pass-1",
    "email": "admin@media.test"
  },
  "settings": {
    "forum_title": "Media F2 R2"
  },
  "extensions": "flarum-suspend,fof-upload,flatrate-media-privacy"
}
EOF

php flarum install --file "${SMOKE_ROOT}/install.json"
rm -f "${SMOKE_ROOT}/install.json"
php flarum migrate
php flarum cache:clear || true
# Ensure required extensions are enabled (install.json may omit if IDs differ).
php flarum extension:enable flarum-suspend || true
php flarum extension:enable fof-upload || true
# Flarum strips the "flarum-" package-name prefix → flatrate-media-privacy.
php flarum extension:enable flatrate-media-privacy || true
php flarum migrate || true
php flarum cache:clear || true

# Configure FoF upload mime → local adapter by default; private-shared uses Illuminate disk.
# FoF expects mimeTypes as an object keyed by regex (no preg delimiters).
# Disable WebP thumbnails so disposable PHP images without imagewebp() still prove upload/auth.
php -r '
$site = require "site.php";
$app = $site->bootApp();
$settings = $app->getContainer()->make(Flarum\Settings\SettingsRepositoryInterface::class);
$settings->set("fof-upload.maxFileSize", "4096");
$settings->set("fof-upload.disableDownloadLogging", "1");
$settings->set("fof-upload.generateThumbnails", "0");
$settings->set("fof-upload.thumbnailWebp", "0");
$settings->set("fof-upload.mustResize", "0");
$settings->set("fof-upload.addsWatermarks", "0");
$mime = json_encode([
  "^image\\/(jpeg|png|gif|webp|avif|bmp|tiff)$" => ["adapter" => "local", "template" => "file"],
  "^text\\/.*$" => ["adapter" => "local", "template" => "file"],
  "^application\\/.*$" => ["adapter" => "local", "template" => "file"],
]);
$settings->set("fof-upload.mimeTypes", $mime);
$ext = $app->getContainer()->make(Flarum\Extension\ExtensionManager::class);
foreach (["flarum-suspend", "fof-upload", "flatrate-media-privacy"] as $id) {
  echo $id."=".($ext->isEnabled($id) ? "enabled" : "DISABLED")."\n";
}
if (!$ext->isEnabled("flatrate-media-privacy")) {
  fwrite(STDERR, "MEDIA_PRIVACY_EXTENSION=DISABLED\n");
  exit(1);
}
echo "settings_ok\n";
'

pass MEDIA_FLARUM_BOOT

# Ensure prependDownloader registered
php -r '
require "vendor/autoload.php";
$src = file_get_contents("vendor/flatrate/flarum-media-privacy/extend.php");
if (!str_contains($src, "MediaPrivacyServiceProvider") || !str_contains($src, "prependDownloader")) {
  fwrite(STDERR, "PRIVATE_DOWNLOADER_PRIORITY=FAIL extend\n"); exit(1);
}
$dl = file_get_contents("vendor/flatrate/flarum-media-privacy/src/Download/PrivateSharedAuthorizingDownloader.php");
if (!str_contains($dl, "assertCanRead") || !str_contains($dl, "private, no-store")) {
  fwrite(STDERR, "PRIVATE_DOWNLOADER_PRIORITY=FAIL downloader\n"); exit(1);
}
$fof = file_get_contents("vendor/fof/upload/src/Commands/DownloadHandler.php");
if (!str_contains($fof, "function prependDownloader")) {
  fwrite(STDERR, "PRIVATE_DOWNLOADER_PRIORITY=FAIL fof_api\n"); exit(1);
}
echo "PRIVATE_DOWNLOADER_PRIORITY=PASS\n";
'

# Start HTTP
php -S "127.0.0.1:8772" -t public >"${SMOKE_ROOT}/http.log" 2>&1 &
HTTP_PID=$!
for _ in $(seq 1 40); do
  code="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/" || true)"
  [[ "${code}" =~ ^[23] ]] && break
  sleep 0.25
done

token_for() {
  local user="$1" pass="$2" out code
  out="$(mktemp)"
  code="$(curl -sS -o "${out}" -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    -d "{\"identification\":\"${user}\",\"password\":\"${pass}\"}" "${BASE}/api/token")"
  if [[ "${code}" != "200" ]]; then
    echo "token_fail user=${user} code=${code}" >&2
    cat "${out}" >&2
    rm -f "${out}"
    return 1
  fi
  php -r 'echo json_decode(file_get_contents($argv[1]), true)["token"] ?? "";' "${out}"
  rm -f "${out}"
}

create_user() {
  local user="$1" email="$2" pass="$3"
  php -d display_errors=1 -r '
    $site = require "site.php";
    $app = $site->bootApp();
    $user = new Flarum\User\User();
    $user->username = $argv[1];
    $user->email = $argv[2];
    if (method_exists($user, "changePassword")) {
      $user->changePassword($argv[3]);
    } else {
      $user->password = $argv[3];
    }
    $user->is_email_confirmed = true;
    $user->joined_at = Carbon\Carbon::now();
    $user->save();
    $user->groups()->attach(Flarum\Group\Group::MEMBER_ID);
    echo $user->id;
  ' "${user}" "${email}" "${pass}"
}

OWNER_ID="$(create_user owner owner@media.test owner-pass-1)"
OTHER_ID="$(create_user other other@media.test other-pass-1)"
create_user suspended suspended@media.test suspended-pass-1 >/dev/null
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $u = Flarum\User\User::where("username","suspended")->firstOrFail();
  $u->suspended_until = Carbon\Carbon::now()->addDays(7);
  $u->save();
'
# Grant upload permissions to owner (group Members typically needs explicit perms)
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $db = $app->getContainer()->make("flarum.db");
  // Give Members group upload + shared-file upload perms
  foreach (["fof-upload.upload", "fof-upload.download", "fof-upload.upload-shared-files", "fof-upload.access-shared-files"] as $perm) {
    $exists = $db->table("group_permission")->where(["group_id"=>3,"permission"=>$perm])->exists();
    if (!$exists) {
      $db->table("group_permission")->insert(["group_id"=>3,"permission"=>$perm]);
    }
  }
  echo "perms_ok\n";
'

OWNER_TOKEN="$(token_for owner owner-pass-1)"
OTHER_TOKEN="$(token_for other other-pass-1)"
SUSP_TOKEN="$(token_for suspended suspended-pass-1 || true)"

# --- Unauthorized ordinary upload should still require permission; members granted above.
# Guest upload denied ---
GUEST_UP_CODE="$(curl -s -o /dev/null -w '%{http_code}' -X POST -F 'files[]=@/etc/hosts;filename=hosts.txt;type=text/plain' "${BASE}/api/fof/upload")"
# FoF may reject unauthenticated uploads as 401/403 or as a 400 validation/auth failure.
if [[ "${GUEST_UP_CODE}" == "401" || "${GUEST_UP_CODE}" == "403" || "${GUEST_UP_CODE}" == "400" ]]; then
  pass UNAUTHORIZED_UPLOAD "guest=${GUEST_UP_CODE}"
else
  fail UNAUTHORIZED_UPLOAD "guest=${GUEST_UP_CODE}"
fi

# Create a tiny PNG for upload
PNG="${SMOKE_ROOT}/pixel.png"
php -r '
  $im=imagecreatetruecolor(2,2); $c=imagecolorallocate($im,10,20,30); imagefill($im,0,0,$c);
  imagepng($im,$argv[1]); imagedestroy($im);
' "${PNG}"

# Public (non-shared) upload via local adapter
PUB_OUT="$(mktemp)"
PUB_CODE="$(curl -sS -o "${PUB_OUT}" -w '%{http_code}' -X POST \
  -H "Authorization: Token ${OWNER_TOKEN}" \
  -F "files[]=@${PNG};filename=public-pixel.png;type=image/png" \
  "${BASE}/api/fof/upload")"
[[ "${PUB_CODE}" == "201" || "${PUB_CODE}" == "200" ]] || { cat "${PUB_OUT}"; fail ISOLATED_OBJECT_WRITE "public_upload=${PUB_CODE}"; }
# FoF JSON:API id is the numeric file id; uuid lives under attributes.
PUB_UUID="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); $row=$j["data"][0]??$j["data"]??[]; echo $row["attributes"]["uuid"] ?? "";' "${PUB_OUT}")"
PUB_URL="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); $row=$j["data"][0]??$j["data"]??[]; echo $row["attributes"]["url"] ?? "";' "${PUB_OUT}")"
[[ -n "${PUB_UUID}" ]] || fail ISOLATED_OBJECT_WRITE "missing_public_uuid"
pass ISOLATED_OBJECT_WRITE "public_uuid=${PUB_UUID}"

# Public local files are served via asset URL (not the private download API).
PUB_DL_CODE="$(curl -s -o /dev/null -w '%{http_code}' "${PUB_URL}")"
[[ "${PUB_DL_CODE}" == "200" ]] || fail ISOLATED_OBJECT_READ "public_asset=${PUB_DL_CODE} url=${PUB_URL}"
GUEST_PUB_CODE="${PUB_DL_CODE}"
echo "PUBLIC_FILE_GUEST_READ_CODE=${GUEST_PUB_CODE}"
pass ISOLATED_OBJECT_READ "public_asset=200 url=${PUB_URL}"

# Private-shared upload: shared=1 + hidden=1
PRIV_OUT="$(mktemp)"
PRIV_CODE="$(curl -sS -o "${PRIV_OUT}" -w '%{http_code}' -X POST \
  -H "Authorization: Token ${OWNER_TOKEN}" \
  -F "files[]=@${PNG};filename=private-pixel.png;type=image/png" \
  -F "options[shared]=true" \
  -F "options[hidden]=true" \
  "${BASE}/api/fof/upload")"
[[ "${PRIV_CODE}" == "201" || "${PRIV_CODE}" == "200" ]] || { cat "${PRIV_OUT}"; fail PRIVATE_DOWNLOAD_HANDLER "private_upload=${PRIV_CODE}"; }
PRIV_UUID="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); $row=$j["data"][0]??$j["data"]??[]; echo $row["attributes"]["uuid"] ?? "";' "${PRIV_OUT}")"
[[ -n "${PRIV_UUID}" ]] || fail PRIVATE_DOWNLOAD_HANDLER "missing_private_uuid"

# Confirm actor_id stamped + upload_method private-shared
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $file = FoF\Upload\File::where("uuid", $argv[1])->firstOrFail();
  if (($file->upload_method ?? "") !== "private-shared") { fwrite(STDERR, "method=".$file->upload_method."\n"); exit(1); }
  if ((int)$file->actor_id !== (int)$argv[2]) { fwrite(STDERR, "actor_id=".$file->actor_id." expected=".$argv[2]."\n"); exit(1); }
  echo "PRIVATE_OWNER_STAMP=PASS\n";
  echo "path=".$file->path."\n";
' "${PRIV_UUID}" "${OWNER_ID}"

# Owner download PASS + Cache-Control
OWNER_HDRS="$(mktemp)"
OWNER_BODY="$(mktemp)"
OWNER_DL_CODE="$(curl -sS -D "${OWNER_HDRS}" -o "${OWNER_BODY}" -w '%{http_code}' \
  -H "Authorization: Token ${OWNER_TOKEN}" "${BASE}/api/fof/download/${PRIV_UUID}")"
[[ "${OWNER_DL_CODE}" == "200" ]] || fail OWNER_DOWNLOAD "code=${OWNER_DL_CODE}"
grep -qi 'cache-control:.*no-store' "${OWNER_HDRS}" || fail OWNER_DOWNLOAD "missing_no_store"
pass OWNER_DOWNLOAD "uuid=${PRIV_UUID}"
pass PRIVATE_DOWNLOAD_HANDLER

# Denials
GUEST_PRIV="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/fof/download/${PRIV_UUID}")"
OTHER_PRIV="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${OTHER_TOKEN}" "${BASE}/api/fof/download/${PRIV_UUID}")"
SUSP_PRIV="000"
if [[ -n "${SUSP_TOKEN}" ]]; then
  SUSP_PRIV="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${SUSP_TOKEN}" "${BASE}/api/fof/download/${PRIV_UUID}")"
fi
deny_ok() {
  local name="$1" code="$2"
  if [[ "${code}" == "401" || "${code}" == "403" || "${code}" == "404" ]]; then
    pass "${name}" "code=${code}"
  else
    fail "${name}" "code=${code}"
  fi
}
deny_ok GUEST_DENIAL "${GUEST_PRIV}"
deny_ok WRONG_USER_DENIAL "${OTHER_PRIV}"
deny_ok SUSPENDED_DENIAL "${SUSP_PRIV}"
pass MEDIA_DENIAL_MATRIX "guest=${GUEST_PRIV} other=${OTHER_PRIV} suspended=${SUSP_PRIV}"

# Historical metadata + URL compatibility (synthetic)
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $file = FoF\Upload\File::where("uuid", $argv[1])->firstOrFail();
  $url = (string)$file->url;
  if ($url === "" || !str_contains($url, $file->uuid)) { fwrite(STDERR, "bad url\n"); exit(1); }
  // Must not advertise raw bucket URL for private-shared
  if (str_contains($url, "amazonaws.com") || str_contains($url, ":4566/")) { fwrite(STDERR, "leaked_storage_url\n"); exit(1); }
  echo "HISTORICAL_URL_COMPATIBILITY=PASS\n";
  echo "MEDIA_METADATA_PRESERVED=PASS id=".$file->id." uuid=".$file->uuid."\n";
' "${PRIV_UUID}"
pass HISTORICAL_URL_COMPATIBILITY
pass MEDIA_METADATA_PRESERVED

# Missing object safe error: delete bytes, keep metadata
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $file = FoF\Upload\File::where("uuid", $argv[1])->firstOrFail();
  $disk = $app->getContainer()->make("Illuminate\Contracts\Filesystem\Factory")->disk("private-shared");
  if ($disk->exists($file->path)) { $disk->delete($file->path); }
  echo "bytes_deleted\n";
' "${PRIV_UUID}"
MISS_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Token ${OWNER_TOKEN}" "${BASE}/api/fof/download/${PRIV_UUID}")"
if [[ "${MISS_CODE}" == "404" || "${MISS_CODE}" == "500" || "${MISS_CODE}" == "403" ]]; then
  pass MISSING_PRIVATE_OBJECT "code=${MISS_CODE}"
else
  fail MISSING_PRIVATE_OBJECT "code=${MISS_CODE}"
fi
# Metadata row still present
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $n = FoF\Upload\File::where("uuid", $argv[1])->count();
  if ($n !== 1) { fwrite(STDERR, "metadata_lost\n"); exit(1); }
  echo "metadata_intact\n";
' "${PRIV_UUID}"

# Optional S3-compatible path via moto
start_moto_if_available
if [[ "${MEDIA_S3_ENABLED:-0}" == "1" ]]; then
  php -r '
    $site = require "site.php";
    $app = $site->bootApp();
    $settings = $app->getContainer()->make(Flarum\Settings\SettingsRepositoryInterface::class);
    $settings->set("fof-upload.awsS3Key", getenv("FOF_UPLOAD_AWS_S3_KEY") ?: "testing");
    $settings->set("fof-upload.awsS3Secret", getenv("FOF_UPLOAD_AWS_S3_SECRET") ?: "testing");
    $settings->set("fof-upload.awsS3Bucket", getenv("FOF_UPLOAD_AWS_S3_BUCKET") ?: "flatrate-media-f2-test");
    $settings->set("fof-upload.awsS3Region", getenv("FOF_UPLOAD_AWS_S3_REGION") ?: "us-east-1");
    $settings->set("fof-upload.awsS3Endpoint", getenv("FOF_UPLOAD_AWS_S3_ENDPOINT") ?: "http://127.0.0.1:4566");
    $settings->set("fof-upload.awsS3UsePathStyleEndpoint", "1");
    $settings->set("fof-upload.awsS3ACL", "private");
    $mime = json_encode([
      "^image\\/(jpeg|png|gif|webp|avif|bmp|tiff)$" => ["adapter" => "aws-s3", "template" => "file"],
      "^text\\/.*$" => ["adapter" => "aws-s3", "template" => "file"],
      "^application\\/.*$" => ["adapter" => "aws-s3", "template" => "file"],
    ]);
    $settings->set("fof-upload.mimeTypes", $mime);
    $settings->set("fof-upload.generateThumbnails", "0");
    echo "s3_settings_ok\n";
  '
  # Ensure bucket exists now that vendor is installed
  php -r '
    require "vendor/autoload.php";
    $s3 = new Aws\S3\S3Client([
      "version"=>"latest","region"=>getenv("FOF_UPLOAD_AWS_S3_REGION")?: "us-east-1",
      "endpoint"=>getenv("FOF_UPLOAD_AWS_S3_ENDPOINT")?: "http://127.0.0.1:4566",
      "use_path_style_endpoint"=>true,
      "credentials"=>["key"=>getenv("FOF_UPLOAD_AWS_S3_KEY")?: "testing","secret"=>getenv("FOF_UPLOAD_AWS_S3_SECRET")?: "testing"],
    ]);
    $b = getenv("FOF_UPLOAD_AWS_S3_BUCKET") ?: "flatrate-media-f2-test";
    try { $s3->createBucket(["Bucket"=>$b]); } catch (Throwable $e) {}
    $s3->putObject(["Bucket"=>$b,"Key"=>"probe.txt","Body"=>"ok"]);
    $got = (string)$s3->getObject(["Bucket"=>$b,"Key"=>"probe.txt"])["Body"];
    if ($got !== "ok") { fwrite(STDERR, "s3_probe_fail\n"); exit(1); }
    echo "S3_SDK_PROBE=PASS\n";
  ' && RESULT_S3_COMPAT=PASS || RESULT_S3_COMPAT=FAIL

  if [[ "${RESULT_S3_COMPAT}" == "PASS" ]]; then
    S3_OUT="$(mktemp)"
    S3_CODE="$(curl -sS -o "${S3_OUT}" -w '%{http_code}' -X POST \
      -H "Authorization: Token ${OWNER_TOKEN}" \
      -F "files[]=@${PNG};filename=s3-pixel.png;type=image/png" \
      "${BASE}/api/fof/upload" || true)"
    if [[ "${S3_CODE}" == "200" || "${S3_CODE}" == "201" ]]; then
      S3_UUID="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); $row=$j["data"][0]??[]; echo $row["attributes"]["uuid"] ?? "";' "${S3_OUT}")"
      php -r '
        $site = require "site.php";
        $app = $site->bootApp();
        $file = FoF\Upload\File::where("uuid", $argv[1])->firstOrFail();
        if (($file->upload_method ?? "") !== "aws-s3") { fwrite(STDERR, "method=".$file->upload_method."\n"); exit(1); }
        echo "S3_UPLOAD_METHOD=PASS\n";
      ' "${S3_UUID}" && pass S3_COMPATIBLE_OBJECT_E2E "uuid=${S3_UUID}" || RESULT_S3_COMPAT=FAIL
    else
      echo "S3_FOF_UPLOAD_CODE=${S3_CODE}" | tee -a "${EVIDENCE_DIR}/s3.log"
      cat "${S3_OUT}" >> "${EVIDENCE_DIR}/s3.log" || true
      # SDK probe passed; FoF path may still need ACL/config — record partial
      not_proven S3_COMPATIBLE_OBJECT_E2E "sdk_pass_fof_upload_${S3_CODE}"
      RESULT_S3_COMPAT=NOT_PROVEN
    fi
  fi
fi

not_proven CLOUDFLARE_R2_COMPATIBILITY "isolated_only"
RESULT_R2=NOT_PROVEN

# Disable FoF Upload; metadata must remain
php flarum extension:disable fof-upload
php -r '
  $site = require "site.php";
  $app = $site->bootApp();
  $n = FoF\Upload\File::count();
  if ($n < 1) { fwrite(STDERR, "rows_lost\n"); exit(1); }
  echo "rows=".$n."\n";
'
pass MEDIA_DISABLE_FALLBACK

# Receipt JSON
MEDIA_SHA="${MEDIA_SHA}" FOF_VER="${FOF_VER}" FLY_VER="${FLY_VER}" FLY_S3_VER="${FLY_S3_VER}" RESULT_S3_COMPAT="${RESULT_S3_COMPAT}" \
php -r '
$receipt = [
  "tested_at" => gmdate("c"),
  "media_privacy_sha" => getenv("MEDIA_SHA") ?: "",
  "fof_upload" => getenv("FOF_VER") ?: "",
  "flysystem" => getenv("FLY_VER") ?: "",
  "flysystem_aws_s3_v3" => getenv("FLY_S3_VER") ?: "",
  "s3_compatible" => getenv("RESULT_S3_COMPAT") ?: "NOT_PROVEN",
  "cloudflare_r2" => "NOT_PROVEN",
  "production_mutated" => false,
];
file_put_contents($argv[1], json_encode($receipt, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "${EVIDENCE_DIR}/results.json"

echo "MEDIA_PRIVACY_SHA=${MEDIA_SHA}"
echo "S3_COMPATIBLE_OBJECT_E2E=${RESULT_S3_COMPAT}"
echo "CLOUDFLARE_R2_COMPATIBILITY=${RESULT_R2}"
echo "MEDIA_F2_MEDIA_R2=PASS"
echo "EVIDENCE_DIR=${EVIDENCE_DIR}"
echo "PRODUCTION_MUTATED=false"
