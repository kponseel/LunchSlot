<?php
/**
 * Gabarit HTML des pages web : en-tête, pied de page, styles sobres.
 */

declare(strict_types=1);

function page_header(string $title, ?array $organizer = null): void
{
    $app = h(config('app_name', 'LunchSlot'));
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' — ' . $app . '</title>';
    echo '<style>' . page_css() . '</style></head><body>';
    echo '<header class="topbar"><div class="wrap">';
    $base = h(rtrim(config('app_url'), '/'));
    echo '<a class="brand" href="' . $base . '/index.php">' . $app . '</a>';
    echo '<nav>';
    if ($organizer) {
        echo '<a href="' . $base . '/mes-dejeuners.php">' . h(__('nav.my_lunches')) . '</a>';
        echo '<a href="' . $base . '/creer.php">' . h(__('nav.new_lunch')) . '</a>';
        echo '<a href="' . $base . '/logout.php">' . h(__('nav.logout')) . '</a>';
    }
    echo ' ' . locale_switcher_html();
    echo '</nav></div></header><main class="wrap">';

    foreach (take_flashes() as $f) {
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
}

function page_footer(): void
{
    echo '</main><footer class="wrap muted">' . h(config('app_name', 'LunchSlot'))
        . ' — ' . h(__('footer.tagline')) . '</footer></body></html>';
}

function status_badge(string $status): string
{
    $cls = ['en_attente' => 'badge-wait', 'confirme' => 'badge-ok', 'annule' => 'badge-cancel'][$status] ?? '';
    return '<span class="badge ' . $cls . '">' . h(__('status.' . $status)) . '</span>';
}

function page_css(): string
{
    return <<<CSS
:root{--bg:#f4f5f7;--card:#fff;--line:#e3e6ea;--ink:#222;--muted:#8a8f98;--brand:#2b5a9e;--ok:#1f8b4c;--warn:#b8860b;--bad:#b23b3b;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.5;}
.wrap{max-width:920px;margin:0 auto;padding:16px;}
.topbar{background:var(--card);border-bottom:1px solid var(--line);}
.topbar .wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.brand{font-weight:700;color:var(--brand);text-decoration:none;font-size:18px;}
nav a{color:var(--ink);text-decoration:none;margin-left:14px;font-size:14px;}
nav a:hover{color:var(--brand);text-decoration:underline;}
.langsw{margin-left:14px;font-size:13px;color:var(--muted);}
.langsw a{margin-left:0;color:var(--muted);}
h1{font-size:22px;margin:8px 0 16px;}
h2{font-size:17px;margin:22px 0 10px;}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:18px;margin:14px 0;}
label{display:block;font-size:14px;margin:10px 0 4px;font-weight:600;}
input,select,textarea{width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:6px;font-size:15px;background:#fff;}
button,.btn{background:var(--brand);color:#fff;border:0;border-radius:6px;padding:10px 16px;font-size:15px;cursor:pointer;text-decoration:none;display:inline-block;}
button:hover,.btn:hover{opacity:.92;}
.btn-sec{background:#eef1f5;color:var(--ink);}
.btn-danger{background:var(--bad);}
.btn-small{padding:6px 10px;font-size:13px;}
.muted{color:var(--muted);font-size:13px;}
.flash{padding:10px 14px;border-radius:6px;margin:10px 0;font-size:14px;}
.flash.info{background:#e8f0fb;border:1px solid #c6dbf6;}
.flash.success{background:#e7f6ec;border:1px solid #bfe6cd;}
.flash.error{background:#fbeaea;border:1px solid #f2c6c6;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{border:1px solid var(--line);padding:8px 10px;text-align:left;}
th{background:#f7f9fc;}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:600;}
.badge-ok{background:#e7f6ec;color:var(--ok);}
.badge-wait{background:#fff5e0;color:var(--warn);}
.badge-cancel{background:#fbeaea;color:var(--bad);}
.yes{color:var(--ok);font-weight:700;}
.no{color:var(--bad);font-weight:700;}
.na{color:var(--muted);}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.row>div{flex:1;min-width:140px;}
.inline{display:inline;}
.slot-line{display:flex;gap:14px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line);flex-wrap:wrap;}
.dyn-row{display:flex;gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap;}
.dyn-row input[type=text],.dyn-row input[type=email]{flex:1;min-width:140px;}
.dyn-row input[type=number]{width:90px;}
.dyn-del{flex:0 0 auto;line-height:1;}
.copy{font-family:monospace;font-size:12px;background:#f2f4f7;padding:4px 6px;border-radius:4px;word-break:break-all;}
@media(max-width:560px){table{font-size:13px}th,td{padding:6px}}
CSS;
}
