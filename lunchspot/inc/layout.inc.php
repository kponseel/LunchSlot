<?php
/**
 * Gabarit HTML des pages : en-tête, pied de page, design system (mobile-first,
 * épuré, clair/sombre). Les classes historiques sont conservées pour que
 * toutes les pages bénéficient du nouveau style.
 */

declare(strict_types=1);

function page_header(string $title, ?array $organizer = null): void
{
    $app = h(config('app_name', 'LunchSpot'));
    $base = h(rtrim(config('app_url'), '/'));
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="' . h(current_locale()) . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<meta name="color-scheme" content="light dark">';
    echo '<meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">';
    echo '<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">';
    echo '<title>' . h($title) . ' — ' . $app . '</title>';
    echo '<style>' . page_css() . '</style></head><body>';

    echo '<header class="topbar"><div class="wrap bar">';
    echo '<a class="brand" href="' . $base . '/index.php">' . $app . '</a>';
    echo '<div class="bar-actions">';
    if ($organizer) {
        echo '<a class="navlink" href="' . $base . '/mes-dejeuners.php">' . h(__('nav.my_lunches')) . '</a>';
        echo '<a class="navlink" href="' . $base . '/logout.php">' . h(__('nav.logout')) . '</a>';
    }
    echo locale_switcher_html();
    echo '</div></div></header><main class="wrap">';

    foreach (take_flashes() as $f) {
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
}

function page_footer(): void
{
    echo '</main><footer class="wrap foot">' . h(config('app_name', 'LunchSpot'))
        . ' · ' . h(__('footer.tagline')) . '</footer></body></html>';
}

function status_badge(string $status): string
{
    $cls = ['en_attente' => 'badge-wait', 'confirme' => 'badge-ok', 'annule' => 'badge-cancel'][$status] ?? '';
    return '<span class="badge ' . $cls . '">' . h(__('status.' . $status)) . '</span>';
}

function page_css(): string
{
    return <<<CSS
:root{
  --bg:#f5f5f7; --surface:#ffffff; --surface-2:#eeeef0;
  --ink:#1d1d1f; --ink-2:#6e6e73; --line:rgba(0,0,0,.09);
  --accent:#0071e3; --accent-ink:#ffffff; --accent-soft:rgba(0,113,227,.10);
  --ok:#1a8f43; --ok-soft:rgba(52,199,89,.15);
  --warn:#a8620a; --warn-soft:rgba(255,159,10,.16);
  --bad:#d23b34; --bad-soft:rgba(255,59,48,.12);
  --shadow:0 1px 2px rgba(0,0,0,.04), 0 10px 30px rgba(0,0,0,.06);
  --radius:18px; --radius-s:12px;
}
@media (prefers-color-scheme: dark){
  :root{
    --bg:#000000; --surface:#1c1c1e; --surface-2:#2c2c2e;
    --ink:#f5f5f7; --ink-2:#9a9a9f; --line:rgba(255,255,255,.13);
    --accent:#0a84ff; --accent-soft:rgba(10,132,255,.18);
    --ok:#30d158; --ok-soft:rgba(48,209,88,.18);
    --warn:#ff9f0a; --warn-soft:rgba(255,159,10,.20);
    --bad:#ff453a; --bad-soft:rgba(255,69,58,.20);
    --shadow:0 1px 2px rgba(0,0,0,.4), 0 10px 30px rgba(0,0,0,.5);
  }
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  font-size:17px;line-height:1.47;-webkit-font-smoothing:antialiased;letter-spacing:-.01em}
a{color:var(--accent);text-decoration:none}
.wrap{max-width:600px;margin:0 auto;padding:0 20px}
.wrap.bar{max-width:920px}

.topbar{position:sticky;top:0;z-index:20;background:color-mix(in srgb,var(--bg) 80%, transparent);
  backdrop-filter:saturate(180%) blur(20px);-webkit-backdrop-filter:saturate(180%) blur(20px);
  border-bottom:1px solid var(--line);padding-top:env(safe-area-inset-top)}
.bar{display:flex;align-items:center;justify-content:space-between;height:52px;gap:12px}
.brand{font-weight:700;font-size:19px;color:var(--ink);letter-spacing:-.02em}
.bar-actions{display:flex;align-items:center;gap:16px}
.navlink{font-size:15px;color:var(--accent)}
.langsw{font-size:13px;color:var(--ink-2);display:inline-flex;gap:6px}
.langsw a{color:var(--ink-2)} .langsw strong{color:var(--ink)}

h1{font-size:30px;line-height:1.15;letter-spacing:-.03em;font-weight:700;margin:24px 0 6px}
h2{font-size:20px;letter-spacing:-.02em;font-weight:650;margin:28px 0 12px}
.sub{color:var(--ink-2);font-size:16px;margin:0 0 8px}
.foot{color:var(--ink-2);font-size:13px;text-align:center;padding:32px 20px calc(28px + env(safe-area-inset-bottom))}

.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:20px;margin:16px 0;box-shadow:var(--shadow)}

label{display:block;font-size:14px;font-weight:600;color:var(--ink-2);margin:16px 0 7px}
form .card>label:first-child{margin-top:0}
input,select,textarea{width:100%;padding:13px 14px;border:1px solid var(--line);border-radius:var(--radius-s);
  font-size:17px;background:var(--surface);color:var(--ink);appearance:none;-webkit-appearance:none;
  transition:border-color .15s, box-shadow .15s;font-family:inherit}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 4px var(--accent-soft)}
input[type=checkbox],input[type=radio]{width:auto;appearance:auto;-webkit-appearance:auto}
::placeholder{color:var(--ink-2);opacity:.7}
.help{font-size:13px;color:var(--ink-2);margin:6px 2px 0}

.btn,button{font-family:inherit;font-size:17px;font-weight:600;border:0;border-radius:var(--radius-s);
  padding:13px 20px;background:var(--accent);color:var(--accent-ink);cursor:pointer;text-decoration:none;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;
  transition:transform .06s ease, filter .15s}
.btn:active,button:active{transform:scale(.98)}
.btn:hover,button:hover{filter:brightness(1.05)}
.btn-block{display:flex;width:100%}
.btn-sec{background:var(--accent-soft);color:var(--accent)}
.btn-danger{background:transparent;color:var(--bad);border:1px solid var(--line)}
.btn-danger:hover{background:var(--bad-soft);filter:none}
.btn-small{min-height:38px;padding:8px 14px;font-size:15px;border-radius:10px}
.btn-icon{min-height:40px;min-width:40px;padding:0 12px;border-radius:10px;background:var(--surface-2);color:var(--ink);font-size:15px}

.seg{display:inline-flex;background:var(--surface-2);border-radius:11px;padding:3px;gap:3px;width:100%;max-width:260px}
.seg-item{position:relative;flex:1;display:flex}
.seg label{flex:1;margin:0;text-align:center;font-size:15px;font-weight:600;color:var(--ink);
  padding:9px 8px;border-radius:9px;cursor:pointer;transition:all .15s;user-select:none}
.seg input{position:absolute;opacity:0;pointer-events:none}
.seg .seg-yes input:checked + label{background:var(--surface);box-shadow:0 1px 3px rgba(0,0,0,.14);color:var(--ok)}
.seg .seg-no input:checked + label{background:var(--surface);box-shadow:0 1px 3px rgba(0,0,0,.14);color:var(--bad)}

.switch{position:relative;display:inline-block;width:52px;height:32px;flex:0 0 auto}
.switch input{opacity:0;width:0;height:0}
.switch .track{position:absolute;inset:0;background:var(--surface-2);border-radius:999px;transition:.2s;cursor:pointer}
.switch .track:before{content:"";position:absolute;height:28px;width:28px;left:2px;top:2px;background:#fff;
  border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.switch input:checked + .track{background:var(--ok)}
.switch input:checked + .track:before{transform:translateX(20px)}
.switch-row{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:8px 0}
.switch-row .lbl{color:var(--ink);font-size:16px;font-weight:500}

.badge{display:inline-flex;align-items:center;padding:5px 12px;border-radius:999px;font-size:13px;font-weight:600}
.badge-ok{background:var(--ok-soft);color:var(--ok)}
.badge-wait{background:var(--warn-soft);color:var(--warn)}
.badge-cancel{background:var(--bad-soft);color:var(--bad)}
.muted{color:var(--ink-2);font-size:14px}

.flash{padding:13px 16px;border-radius:var(--radius-s);margin:14px 0;font-size:15px;font-weight:500}
.flash.info{background:var(--accent-soft);color:var(--accent)}
.flash.success{background:var(--ok-soft);color:var(--ok)}
.flash.error{background:var(--bad-soft);color:var(--bad)}
.warn-inline{display:none;background:var(--warn-soft);color:var(--warn);padding:10px 14px;border-radius:10px;font-size:14px;font-weight:600;margin:8px 0}
.warn-inline.show{display:block}

.list{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);margin:12px 0}
.list .item{display:flex;align-items:center;gap:14px;padding:16px;border-bottom:1px solid var(--line);color:var(--ink)}
.list .item:last-child{border-bottom:0}
a.item:active{background:var(--surface-2)}
.item .grow{flex:1;min-width:0}
.item .t{font-weight:600;font-size:17px}
.item .d{color:var(--ink-2);font-size:14px;margin-top:2px}
.chev{color:var(--ink-2);font-size:20px;flex:0 0 auto}

.matrix-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);box-shadow:var(--shadow);margin:12px 0}
table{width:100%;border-collapse:collapse;font-size:15px}
th,td{padding:12px;text-align:left;border-bottom:1px solid var(--line);white-space:nowrap}
thead th{font-size:13px;color:var(--ink-2);font-weight:600}
tbody tr:last-child td{border-bottom:0}
td.slotcell{position:sticky;left:0;background:var(--surface);font-weight:600;white-space:normal;min-width:150px}
.cell{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;font-weight:700;font-size:14px}
.cell.yes{background:var(--ok-soft);color:var(--ok)} .cell.no{background:var(--bad-soft);color:var(--bad)} .cell.na{color:var(--ink-2)}

.dyn-row{display:flex;gap:8px;align-items:center;margin:0 0 10px;flex-wrap:wrap}
.dyn-row.dup{outline:2px solid var(--warn);outline-offset:3px;border-radius:12px}
.dyn-grid{flex:1;display:grid;grid-template-columns:1.3fr .9fr .8fr;gap:8px;min-width:0}
.dyn-row .name{flex:1;min-width:120px} .dyn-row .email{flex:1.4;min-width:150px}
.rowbtns{display:flex;gap:6px;flex:0 0 auto}
.copy{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:var(--ink-2);word-break:break-all}
.slot-line{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 0;border-bottom:1px solid var(--line);flex-wrap:wrap}
.slot-line:last-child{border-bottom:0}
.slot-line .st{font-weight:600;font-size:16px}

.hero{padding:36px 0 4px}
.hero h1{font-size:34px;margin-bottom:10px}
.center{text-align:center}
.row{display:flex;gap:10px;flex-wrap:wrap}
.row>div{flex:1;min-width:120px}

@media (max-width:560px){
  h1{font-size:26px} .hero h1{font-size:30px}
  .dyn-grid{grid-template-columns:1fr 1fr}
  .dyn-grid .fdate{grid-column:1 / -1}
  .bar-actions{gap:12px}
}
CSS;
}
