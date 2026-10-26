<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$notificationToastUserId = (int)($_SESSION['user']['id'] ?? 0);
if ($notificationToastUserId <= 0) {
    return;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $db = __DIR__ . '/../config/database.php';
    if (is_file($db)) {
        require_once $db;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

if (empty($_SESSION['notification_toast_csrf'])) {
    $_SESSION['notification_toast_csrf'] = bin2hex(random_bytes(32));
}
$notificationToastCsrf = (string)$_SESSION['notification_toast_csrf'];

$notificationToastRows = [];
try {
    $stmt = $conn->prepare("SELECT id, tieu_de, noi_dung, loai, lien_ket, ngay_tao FROM thong_bao WHERE nguoi_dung_id = ? AND da_doc = 0 ORDER BY ngay_tao DESC, id DESC LIMIT 6");
    if ($stmt) {
        $stmt->bind_param('i', $notificationToastUserId);
        $stmt->execute();
        $notificationToastRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('Toast notification load error: ' . $e->getMessage());
}

if (!$notificationToastRows) {
    return;
}

if (!function_exists('notificationToastIcon')) {
    function notificationToastIcon(string $type): string {
        $type = mb_strtolower(trim($type), 'UTF-8');
        if ($type === 'khuyen_mai_sap_het_1h') return '🔥';
        if ($type === 'khuyen_mai_sap_het_24h') return '⏰';
        if ($type === 'san_pham_het_hang') return '🚨';
        if ($type === 'san_pham_sap_het') return '⚠️';
        if (str_contains($type, 'don') || str_contains($type, 'dat_hang')) return '📦';
        if (str_contains($type, 'thanh_toan') || str_contains($type, 'hoan_tien')) return '💳';
        if (str_contains($type, 'danh_gia') || str_contains($type, 'phan_hoi')) return '⭐';
        if (str_contains($type, 'huy')) return '⚠️';
        return '🔔';
    }
}

if (!function_exists('notificationToastTone')) {
    function notificationToastTone(string $type): string {
        $type = mb_strtolower(trim($type), 'UTF-8');
        if (in_array($type, ['san_pham_het_hang', 'khuyen_mai_sap_het_1h'], true) || str_contains($type, 'huy')) return 'danger';
        if (in_array($type, ['san_pham_sap_het', 'khuyen_mai_sap_het_24h'], true)) return 'warning';
        if (str_contains($type, 'thanh_toan') || str_contains($type, 'hoan_tien')) return 'success';
        if (str_contains($type, 'hoan_thanh') || str_contains($type, 'da_giao') || str_contains($type, 'giao_hang_thanh_cong')) return 'success';
        if (str_contains($type, 'da_duyet') || str_contains($type, 'duyet') || str_contains($type, 'thanh_cong')) return 'success';
        if (str_contains($type, 'danh_gia') || str_contains($type, 'phan_hoi')) return 'info';
        return 'info';
    }
}
?>
<style>
.nt-stack {
    --nt-offset: 6px;
    position: fixed;
    top: 84px;
    right: 20px;
    width: min(400px, calc(100vw - 30px));
    height: 180px;
    z-index: 2147482000;
    pointer-events: none;
}
.nt-toast {
    --nt-accent: #2563eb;
    --nt-index: 0;
    position: absolute;
    inset: 0 0 auto 0;
    width: 100%;
    min-height: 118px;
    border-radius: 18px;
    background: rgba(255,255,255,.98);
    box-shadow: 0 22px 60px rgba(15,23,42,.18), 0 8px 24px rgba(15,23,42,.08);
    transform: translateY(calc(var(--nt-index) * var(--nt-offset))) scale(calc(1 - var(--nt-index) * .006));
    transform-origin: top right;
    opacity: calc(1 - var(--nt-index) * .055);
    transition: transform .42s cubic-bezier(.2,.8,.2,1), opacity .32s ease, filter .32s ease;
    pointer-events: none;
    overflow: visible;
    isolation: isolate;
}
.nt-toast[data-tone="success"] { --nt-accent:#16a34a; }
.nt-toast[data-tone="positive"] { --nt-accent:#0284c7; }
.nt-toast[data-tone="warning"] { --nt-accent:#d97706; }
.nt-toast[data-tone="danger"] { --nt-accent:#dc2626; }
.nt-toast.is-active { pointer-events:auto; }
.nt-toast.is-entering { animation: ntIn .5s cubic-bezier(.2,.9,.22,1.1) both; }
.nt-toast.is-leaving { animation: ntOut .42s cubic-bezier(.55,.05,.65,.95) both; pointer-events:none; }
@keyframes ntIn {
    from { opacity:0; transform:translateY(10px) scale(.985); filter:blur(2px); }
}
@keyframes ntOut {
    to { opacity:0; transform:translateX(125%) translateY(-4px) scale(.96); filter:blur(4px); }
}
.nt-inner {
    position: relative;
    z-index: 2;
    min-height: 118px;
    padding: 17px 48px 16px 17px;
    display: grid;
    grid-template-columns: 44px minmax(0,1fr);
    gap: 13px;
    align-items: start;
    border-radius: 16px;
    background:
        radial-gradient(circle at 0 0, color-mix(in srgb, var(--nt-accent) 8%, transparent), transparent 38%),
        linear-gradient(135deg, rgba(255,255,255,.995), rgba(248,250,252,.975));
}
.nt-icon {
    width:44px;
    height:44px;
    display:grid;
    place-items:center;
    border-radius:14px;
    background: color-mix(in srgb, var(--nt-accent) 12%, white);
    font-size:21px;
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--nt-accent) 18%, transparent);
}
.nt-title { margin:1px 0 5px; color:#0f172a; font-size:14px; font-weight:900; line-height:1.35; }
.nt-message { margin:0; color:#475569; font-size:12.5px; font-weight:500; line-height:1.52; overflow-wrap:anywhere; }
.nt-meta { display:flex; flex-wrap:wrap; gap:7px 10px; align-items:center; margin-top:8px; }
.nt-time { color:#94a3b8; font-size:10.5px; }
.nt-view { padding:0; border:0; background:transparent; color:var(--nt-accent); font-size:10.5px; font-weight:800; cursor:pointer; }
.nt-close {
    position:absolute;
    z-index:4;
    top:11px;
    right:11px;
    width:30px;
    height:30px;
    display:grid;
    place-items:center;
    border:0;
    border-radius:9px;
    background:rgba(241,245,249,.9);
    color:#64748b;
    font-size:18px;
    cursor:pointer;
}
.nt-close:hover { background:#e2e8f0; color:#0f172a; }
.nt-progress {
    position:absolute;
    z-index:6;
    inset:-2px;
    width:calc(100% + 4px);
    height:calc(100% + 4px);
    overflow:visible;
    pointer-events:none;
    filter:drop-shadow(0 0 5px color-mix(in srgb, var(--nt-accent) 32%, transparent));
    opacity:0;
    transition:opacity .22s ease;
}
.nt-toast.is-active .nt-progress { opacity:1; }
.nt-track,.nt-value { fill:none; vector-effect:non-scaling-stroke; }
.nt-track { stroke:rgba(148,163,184,.20); stroke-width:2; }
.nt-value { stroke:var(--nt-accent); stroke-width:2.6; stroke-linecap:round; }
.nt-toast:not(.is-active) .nt-inner {
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--nt-accent) 18%, #e2e8f0);
}
.nt-toast.is-active .nt-inner {
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--nt-accent) 12%, #e2e8f0);
}
.nt-toast.is-paused { box-shadow:0 26px 66px rgba(15,23,42,.22),0 10px 28px rgba(15,23,42,.10); }
@media(max-width:767px){
    .nt-stack{top:72px;right:12px;width:calc(100vw - 24px);--nt-offset:5px;}
    .nt-toast{min-height:110px;border-radius:16px;}
    .nt-inner{min-height:110px;padding:15px 44px 15px 14px;grid-template-columns:40px minmax(0,1fr);gap:11px;}
    .nt-icon{width:40px;height:40px;border-radius:12px;font-size:19px;}
    .nt-title{font-size:13.5px;}
    .nt-message{font-size:12px;}
}
@media(prefers-reduced-motion:reduce){
    .nt-toast,.nt-toast.is-entering,.nt-toast.is-leaving{animation-duration:1ms!important;transition-duration:1ms!important;}
}
</style>

<div class="nt-stack" id="notificationToastStack" aria-live="polite" aria-atomic="false">
    <?php foreach ($notificationToastRows as $i => $toastNotification): ?>
        <?php
        $toastId = (int)$toastNotification['id'];
        $toastLink = trim((string)($toastNotification['lien_ket'] ?? ''));
        $toastType = trim((string)($toastNotification['loai'] ?? ''));
        $toastTimestamp = !empty($toastNotification['ngay_tao']) ? strtotime((string)$toastNotification['ngay_tao']) : false;
        ?>
        <article class="nt-toast <?= $i === 0 ? 'is-active is-entering' : '' ?>"
                 data-id="<?= $toastId ?>"
                 data-link="<?= htmlspecialchars($toastLink, ENT_QUOTES, 'UTF-8') ?>"
                 data-tone="<?= htmlspecialchars(notificationToastTone($toastType), ENT_QUOTES, 'UTF-8') ?>"
                 style="--nt-index:<?= $i ?>;z-index:<?= 100 - $i ?>">
            <div class="nt-inner">
                <div class="nt-icon"><?= notificationToastIcon($toastType) ?></div>
                <div class="nt-content">
                    <div class="nt-title"><?= htmlspecialchars((string)($toastNotification['tieu_de'] ?? 'Thông báo mới'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="nt-message"><?= htmlspecialchars((string)($toastNotification['noi_dung'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="nt-meta">
                        <?php if ($toastTimestamp): ?><span class="nt-time"><?= date('d/m/Y H:i', $toastTimestamp) ?></span><?php endif; ?>
                        <button type="button" class="nt-view" data-open><?= $toastLink !== '' ? 'Xem chi tiết' : 'Đánh dấu đã xem' ?></button>
                    </div>
                </div>
                <button type="button" class="nt-close" data-close aria-label="Đóng" title="Đóng (vẫn giữ chưa đọc)">×</button>
            </div>
            <svg class="nt-progress" aria-hidden="true" preserveAspectRatio="none">
                <rect class="nt-track" x="1.5" y="1.5" rx="20" ry="20" pathLength="100"></rect>
                <rect class="nt-value" x="1.5" y="1.5" rx="20" ry="20" pathLength="100"></rect>
            </svg>
        </article>
    <?php endforeach; ?>
</div>

<script>
(() => {
    const stack = document.getElementById('notificationToastStack');
    if (!stack) return;

    const csrf = <?= json_encode($notificationToastCsrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const endpoint = '/iPhoneStore/includes/notification_toast/mark_read.php';
    const duration = 2000;
    let timer = null;
    let startedAt = 0;
    let remaining = duration;
    let paused = false;
    let raf = 0;

    function items() {
        return Array.from(stack.querySelectorAll('.nt-toast'));
    }

    function layout() {
        items().forEach((el, index) => {
            el.style.setProperty('--nt-index', index);
            el.style.zIndex = String(100 - index);
            el.classList.toggle('is-active', index === 0);
        });
    }

    function geometry(el) {
        if (!el) return;
        const svg = el.querySelector('.nt-progress');
        const track = el.querySelector('.nt-track');
        const value = el.querySelector('.nt-value');
        if (!svg || !track || !value) return;
        const width = Math.max(1, Math.round(el.offsetWidth + 4));
        const height = Math.max(1, Math.round(el.offsetHeight + 4));
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        [track, value].forEach(rect => {
            rect.setAttribute('width', String(Math.max(1, width - 3)));
            rect.setAttribute('height', String(Math.max(1, height - 3)));
        });
        value.style.strokeDasharray = '100';
    }

    function setProgress(el, valueLeft) {
        const value = el?.querySelector('.nt-value');
        if (!value) return;
        const ratio = Math.max(0, Math.min(1, valueLeft / duration));
        value.style.strokeDashoffset = String(100 - ratio * 100);
    }

    function stopActiveTimer() {
        clearTimeout(timer);
        cancelAnimationFrame(raf);
        timer = null;
        raf = 0;
    }

    function animateProgress() {
        const el = items()[0];
        if (!el || paused) return;
        const elapsed = performance.now() - startedAt;
        const left = Math.max(0, remaining - elapsed);
        setProgress(el, left);
        if (left > 0) raf = requestAnimationFrame(animateProgress);
    }

    function startActiveTimer(reset = true) {
        stopActiveTimer();
        const el = items()[0];
        if (!el) {
            stack.remove();
            return;
        }
        geometry(el);
        if (reset) remaining = duration;
        paused = false;
        el.classList.remove('is-paused');
        startedAt = performance.now();
        setProgress(el, remaining);
        raf = requestAnimationFrame(animateProgress);
        timer = setTimeout(() => dismiss(el, false), remaining);
    }

    function pauseActive(el) {
        if (el !== items()[0] || paused) return;
        paused = true;
        clearTimeout(timer);
        cancelAnimationFrame(raf);
        const elapsed = performance.now() - startedAt;
        remaining = Math.max(0, remaining - elapsed);
        setProgress(el, remaining);
        el.classList.add('is-paused');
    }

    function resumeActive(el) {
        if (el !== items()[0] || !paused) return;
        paused = false;
        el.classList.remove('is-paused');
        startedAt = performance.now();
        raf = requestAnimationFrame(animateProgress);
        timer = setTimeout(() => dismiss(el, false), remaining);
    }

    async function mark(id) {
        const body = new URLSearchParams({ notification_id:String(id), csrf_token:csrf });
        const response = await fetch(endpoint, {
            method:'POST',
            credentials:'same-origin',
            keepalive:true,
            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
            body:body.toString()
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Không thể đánh dấu đã xem');
    }

    function dismiss(el, manual = true) {
        if (!el || el.dataset.leaving === '1') return;
        const wasTop = el === items()[0];
        el.dataset.leaving = '1';
        if (wasTop) stopActiveTimer();
        el.classList.add('is-leaving');
        setTimeout(() => {
            el.remove();
            layout();
            const next = items()[0];
            if (!next) {
                stack.remove();
                return;
            }
            if (wasTop) {
                startActiveTimer(true);
            }
        }, 430);
    }

    async function openToast(el) {
        if (!el || el.dataset.busy === '1') return;
        el.dataset.busy = '1';
        try {
            await mark(Number(el.dataset.id));
            const link = el.dataset.link || '';
            if (link) {
                location.href = link;
                return;
            }
            dismiss(el, true);
        } catch (error) {
            el.dataset.busy = '0';
            console.error(error);
        }
    }

    stack.addEventListener('click', event => {
        const el = event.target.closest('.nt-toast');
        if (!el || el !== items()[0]) return;
        if (event.target.closest('[data-close]')) {
            dismiss(el, true);
            return;
        }
        if (event.target.closest('[data-open]') || event.target.closest('.nt-content')) {
            openToast(el);
        }
    });

    stack.addEventListener('mouseenter', event => {
        const el = event.target.closest('.nt-toast');
        if (el) pauseActive(el);
    }, true);

    stack.addEventListener('mouseleave', event => {
        const el = event.target.closest('.nt-toast');
        if (el) resumeActive(el);
    }, true);

    window.addEventListener('resize', () => geometry(items()[0]));

    items().forEach(geometry);
    layout();
    startActiveTimer(true);
})();
</script>
