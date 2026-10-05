<?php
/**
 * Kurage 経営者の制度カレンダー（kseidocal）― 1ファイルPHP。
 *
 * 会社に降ってくる制度の変更（取適法・最低賃金・社会保険の適用拡大・カスハラ対策・ストレスチェック・
 * 雇用保険・子ども子育て支援金・インボイス）を施行日の順に並べ、従業員数などを選ぶと
 * 「自社に関係するもの」と「いつからか」を出す。項目ごとに、国会での直近の議論（国会会議録検索API）・
 * 会社がやること・公式の出典を1ページにまとめる。
 *
 *   /kseidocal.php/          カレンダー（自社に関係するものを選ぶ）
 *   /kseidocal.php/i/<slug>/ 項目ごとのページ
 *   /kseidocal.php/cal.ics   カレンダーアプリに入れる（iCalendar）
 *   /kseidocal.php/about     出典・しないこと
 *   /kseidocal.php/sitemap.xml /robots.txt /llms.txt
 *
 * 日付はすべて省庁の公式ページで確認したもの（2026-10-05）。国会の発言は国立国会図書館 国会会議録検索システムのAPI（1日キャッシュ）。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

const SITE = 'https://kurage.exbridge.jp';
const BASE = SITE . '/kseidocal.php';
const NAME = 'Kurage 経営者の制度カレンダー';
const CHECKED = '2026-10-05';
const CACHE = __DIR__ . '/kseidocal_data/cache';

// 当社のシステム（kappstore）とデモ
const TOOLS = [
    'kchingin'  => ['Kurage 最低賃金・賃上げ助成金チェック', SITE . '/kchingin.php/', '時給の一覧から、人件費の増加と業務改善助成金の目安を計算（無料）', true],
    'ktoriteki' => ['Kurage 取適法チェック', SITE . '/ktoriteki.php/', '対象かどうかと、4つの義務・11の禁止行為を点検（無料）', true],
    'kkintai'   => ['Kurage 勤怠（顔打刻）', 'https://kappstore.exbridge.jp/app.php?id=f0f56c6e4da881be', '週の労働時間を正確に記録し、加入の対象になる人を見落とさない', false],
    'kweborder' => ['Kurage Web Order（受発注）', 'https://kappstore.exbridge.jp/app.php?id=88acc4be142bd444', '発注の内容をWEBで明示し、記録を残す', false],
    'kbilling'  => ['Kurage 請求書（kbilling）', 'https://kappstore.exbridge.jp/app.php?id=15abb025dc2ee4f6', '請求書の発行と入金の確認・支払期日の管理', false],
    'kdealdesk' => ['Kurage Deal Desk（商談の記録）', 'https://kappstore.exbridge.jp/app.php?id=63000166082c7a26', '取引先ごとの協議ややり取りを記録して残す', false],
    'klcrm'     => ['Kurage LINE CRM', 'https://kappstore.exbridge.jp/app.php?id=0e723e981c922df6', 'お客さまとのやり取りを記録し、担当者ひとりに抱えさせない', false],
];

/**
 * 項目。date は施行日（または期限）。size は従業員数の区分ごとに日付が違う項目。
 * need: 関係するかを決める条件（emp=従業員がいる, part=パート・アルバイトがいる, sub=外注・下請けの取引がある,
 *        face=お客さまと接する仕事がある, inv2=インボイスの2割特例を使っている）
 */
const ITEMS = [
    ['slug' => 'kodomo-shienkin', 'seo' => '子ども・子育て支援金はいつから？会社がやること', 'name' => '子ども・子育て支援金の徴収が始まった', 'cat' => '社会保険', 'date' => '2026-04-01', 'when' => '2026年4月分の保険料から（5月の給与天引きから）', 'need' => ['emp'],
     'what' => '健康保険などの医療保険料に上乗せして、子ども・子育て支援金を納めます。被用者保険の支援金率は0.23%で、労使で折半します。',
     'todo' => ['給与計算で、健康保険料とあわせて支援金の分が引かれているかを確かめる', '従業員に、給与明細の控除が増えた理由を説明できるようにする'],
     'src' => ['こども家庭庁「子ども・子育て支援金制度について」', 'https://www.cfa.go.jp/policies/kodomokosodateshienkinseido'], 'kokkai' => '子ども・子育て支援金', 'tools' => []],
    ['slug' => 'toriteki', 'seo' => '取適法はいつから？会社がやること（下請法改正）', 'name' => '取適法（下請法の改正）が施行された', 'cat' => '取引', 'date' => '2026-01-01', 'when' => '2026年1月1日施行', 'need' => ['sub'],
     'what' => '下請法が改正されて取適法（中小受託取引適正化法）になりました。手形払の禁止、価格の協議に応じない一方的な代金決定の禁止、従業員数の基準（300人・100人）、運送委託の追加などが加わりました。',
     'todo' => ['外注・下請けの取引が取適法の対象かを、資本金と従業員数で確かめる', '支払を手形から、支払期日（受領後60日以内）に満額の現金で払う形にする', '価格の協議を求められたら応じ、説明と記録を残す'],
     'src' => ['公正取引委員会「委託事業者の義務」', 'https://www.jftc.go.jp/toriteki/toritekigaiyo/oyagimu.html'], 'kokkai' => '中小受託取引適正化法', 'tools' => ['ktoriteki', 'kweborder', 'kbilling', 'kdealdesk']],
    ['slug' => 'invoice-2wari', 'seo' => 'インボイス2割特例はいつまで？終了後の選び方', 'name' => 'インボイスの2割特例が終わる', 'cat' => '税', 'date' => '2026-09-30', 'when' => '2026年9月30日を含む課税期間まで', 'need' => ['inv2'],
     'what' => 'インボイス発行事業者になった小規模事業者が、納める消費税を売上げの税額の2割にできる「2割特例」は、2026年9月30日を含む課税期間で終わります。令和8年度の税制改正で、個人事業者には令和9年分・令和10年分の「3割特例」ができました。',
     'todo' => ['次の課税期間から、一般課税・簡易課税（個人は3割特例も）のどれにするかを税理士と決める', '簡易課税を選ぶなら、届出の期限を確かめる', '請求書・領収書の発行と保存の仕組みを見直す'],
     'src' => ['国税庁「令和8年度税制改正特集」', 'https://www.nta.go.jp/taxes/shiraberu/zeimokubetsu/shohi/keigenzeiritsu/invoice-review/index.htm'], 'kokkai' => '二割特例', 'tools' => ['kbilling']],
    ['slug' => 'saitei-chingin', 'seo' => '最低賃金2026はいつから？会社がやること', 'name' => '最低賃金が上がる（全国平均1,177円）', 'cat' => '賃金', 'date' => '2026-10-01', 'when' => '2026年10月1日〜12月2日（都道府県ごと）', 'need' => ['emp'],
     'what' => '令和8年度の地域別最低賃金は、全国加重平均で1,177円（56円の引上げ）。都道府県ごとに10月1日から12月2日にかけて発効します。愛知県は1,195円で10月1日から。',
     'todo' => ['時給・日給・月給の人について、時間あたりの額が新しい最低賃金以上かを確かめる', '発効日より前に、賃金規程と給与計算を直す', '業務改善助成金を使うなら、申請は発効日の前日まで（遅くとも11月30日）'],
     'src' => ['厚生労働省「令和8年度 地域別最低賃金 答申状況」', 'https://www.mhlw.go.jp/content/11302000/001745621.pdf'], 'kokkai' => '最低賃金', 'tools' => ['kchingin', 'kkintai']],
    ['slug' => 'shaho-chingin-yoken', 'seo' => '106万円の壁の撤廃はいつから？会社がやること', 'name' => '社会保険の賃金要件（月8.8万円・106万円の壁）がなくなった', 'cat' => '社会保険', 'date' => '2026-10-01', 'when' => '2026年10月', 'need' => ['part'],
     'what' => 'パート・アルバイトが社会保険（厚生年金・健康保険）に入る要件のうち、「所定内賃金が月額8.8万円以上」の賃金要件が、令和7年の年金制度改正で2026年10月に撤廃されました。',
     'todo' => ['社会保険の適用事業所で、週の所定労働時間が20時間以上のパート・アルバイトを洗い出す', '新しく加入になる人に、手取りと将来の年金の変わり方を説明する', '会社負担の保険料の増加を見込む'],
     'src' => ['厚生労働省「社会保険適用拡大特設サイト」', 'https://www.mhlw.go.jp/tekiyoukakudai/seido/tanjikan'], 'kokkai' => '適用拡大 賃金要件', 'tools' => ['kkintai']],
    ['slug' => 'kasuhara', 'seo' => 'カスハラ対策の義務化はいつから？会社がやること', 'name' => 'カスタマーハラスメント対策が事業主の義務になる', 'cat' => '労務', 'date' => '2026-10-01', 'when' => '2026年10月1日施行', 'need' => ['face'],
     'what' => '改正労働施策総合推進法で、カスタマーハラスメントから従業員を守るための雇用管理上の措置が、すべての事業主の義務になりました。求職者に対するセクシュアルハラスメントの防止も義務になります。',
     'todo' => ['カスハラへの方針を決めて、従業員とお客さまに示す', '従業員が相談できる窓口と、対応の手順を決める', '起きたことを記録し、担当者ひとりで抱えさせない体制にする'],
     'src' => ['厚生労働省「職場におけるハラスメントの防止のために」', 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/koyou_roudou/koyoukintou/seisaku06/index.html'], 'kokkai' => 'カスタマーハラスメント', 'tools' => ['klcrm', 'kdealdesk']],
    ['slug' => 'gyomu-kaizen-joseikin', 'seo' => '業務改善助成金2026の締切はいつ？都道府県で違う', 'name' => '業務改善助成金（令和8年度）の申請締切', 'cat' => '助成金', 'date' => '2026-11-30', 'when' => '最低賃金の発効日の前日か11月30日の早い方（都道府県ごと）', 'need' => ['emp'],
     'what' => '事業場でいちばん低い時給を50円以上引き上げ、生産性を上げる設備投資をすると、費用の3/4〜4/5（上限30万〜600万円）が出ます。10月1日に最低賃金が上がった都府県は9月30日で締め切られました。',
     'todo' => ['自社の都道府県の締切を確かめる', '引上げと設備投資の計画を立て、交付決定の後に実施する', '対象経費の例（顧客管理情報のシステム化・POSレジ）に当たる投資を考える'],
     'src' => ['厚生労働省「業務改善助成金」', 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/koyou_roudou/roudoukijun/zigyonushi/shienjigyou/03.html'], 'kokkai' => '業務改善助成金', 'tools' => ['kchingin']],
    ['slug' => 'shaho-kibo', 'seo' => '社会保険の適用拡大はいつから？従業員数別の年月', 'name' => '社会保険の適用拡大（企業規模の要件が段階的になくなる）', 'cat' => '社会保険', 'date' => '2027-10-01', 'when' => '従業員数で変わる：36〜50人は2027年10月、21〜35人は2029年10月、11〜20人は2032年10月、10人以下は2035年10月', 'need' => ['part'],
     'size' => ['1' => '2035-10-01', '11' => '2032-10-01', '21' => '2029-10-01', '36' => '2027-10-01', '51' => null],
     'what' => 'パート・アルバイトが社会保険に入る要件のうち、企業規模（厚生年金の被保険者数）の要件が段階的になくなります。51人以上の企業はすでに対象です。従業員数は、フルタイムとその4分の3以上働く人の数（厚生年金保険の被保険者数）で、法人全体で数えます。',
     'todo' => ['自社の厚生年金保険の被保険者数を、日本年金機構の事業所検索で確かめる', '対象になる年月までに、週20時間以上のパート・アルバイトを洗い出し、本人と働き方を話し合う', '会社負担の保険料を見込み、価格や人員の計画に入れる'],
     'src' => ['厚生労働省「社会保険適用拡大特設サイト」', 'https://www.mhlw.go.jp/tekiyoukakudai/'], 'kokkai' => '適用拡大 企業規模要件', 'tools' => ['kkintai']],
    ['slug' => 'stress-check', 'seo' => 'ストレスチェック義務化はいつから？50人未満の事業場', 'name' => 'ストレスチェックが50人未満の事業場でも義務になる', 'cat' => '労務', 'date' => '2028-04-01', 'when' => '2028年4月1日施行', 'need' => ['emp', 'lt50'],
     'what' => '改正労働安全衛生法で、これまで努力義務だった労働者数50人未満の事業場にも、ストレスチェックの実施が義務になります。',
     'todo' => ['実施する人（医師・保健師など）と、外部に頼むかを決める', '結果を本人の同意なく会社が見ない仕組みにする', '厚生労働省の小規模事業場向けマニュアルを読む'],
     'src' => ['厚生労働省「ストレスチェック制度・メンタルヘルス対策」', 'https://www.mhlw.go.jp/bunya/roudoukijun/anzeneisei12/index.html'], 'kokkai' => 'ストレスチェック 五十人未満', 'tools' => []],
    ['slug' => 'koyou-hoken-10h', 'seo' => '雇用保険の週10時間はいつから？会社がやること', 'name' => '雇用保険の対象が週10時間以上に広がる', 'cat' => '社会保険', 'date' => '2028-10-01', 'when' => '2028年10月1日施行', 'need' => ['part'],
     'what' => '雇用保険に入る要件のうち、週の所定労働時間が「20時間以上」から「10時間以上」になります。新しく入る人も、基本手当・教育訓練給付・育児休業給付などの対象です。',
     'todo' => ['週10時間以上20時間未満で働く人を洗い出す', '雇用保険料の負担の増加を見込む', '労働時間を正確に記録する'],
     'src' => ['厚生労働省「令和6年雇用保険制度改正（令和10年10月1日施行分）について」', 'https://www.mhlw.go.jp/content/11601000/001542937.pdf'], 'kokkai' => '雇用保険 週十時間', 'tools' => ['kkintai']],
];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function u(string $p): string { return BASE . $p; }
function jd(string $ymd): string { [$y, $m, $d] = array_map('intval', explode('-', $ymd)); return $y . '年' . $m . '月' . $d . '日'; }
function today(): string { return date('Y-m-d'); }
function days(string $ymd): int { return (int)round((strtotime($ymd) - strtotime(today())) / 86400); }
function item(string $slug): ?array { foreach (ITEMS as $it) { if ($it['slug'] === $slug) return $it; } return null; }
function status(string $date): string {
    $d = days($date);
    if ($d > 0) return '<b class="soon">あと' . number_format($d) . '日</b>';
    if ($d === 0) return '<b class="soon">今日から</b>';
    return '<span class="done">施行済み（' . number_format(-$d) . '日前）</span>';
}

/** 国会会議録検索API（国立国会図書館）。キーワードの直近の発言を3件。1日キャッシュ */
function kokkai(string $kw): array {
    $f = CACHE . '/' . md5($kw) . '.json';
    if (is_file($f) && filemtime($f) > time() - 86400) { $c = json_decode((string)file_get_contents($f), true); if (is_array($c)) return $c; }
    $url = 'https://kokkai.ndl.go.jp/api/speech?' . http_build_query(['any' => $kw, 'recordPacking' => 'json', 'maximumRecords' => 8]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'kseidocal/1.0 (kurage.exbridge.jp)']);
    $raw = curl_exec($ch); curl_close($ch);
    $d = $raw ? json_decode((string)$raw, true) : null;
    if (!is_array($d)) { return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }
    $out = ['total' => (int)($d['numberOfRecords'] ?? 0), 'rows' => []];
    $first = explode(' ', $kw)[0];
    foreach ($d['speechRecord'] ?? [] as $r) {
        if (($r['speaker'] ?? '') === '会議録情報') continue;
        $sp = preg_replace('/\s+/u', ' ', (string)($r['speech'] ?? ''));
        $p = mb_strpos($sp, $first); $p = $p === false ? 0 : max(0, $p - 50);
        $out['rows'][] = ['date' => $r['date'], 'house' => $r['nameOfHouse'], 'meeting' => $r['nameOfMeeting'], 'speaker' => $r['speaker'],
                          'url' => $r['speechURL'], 'text' => mb_substr($sp, $p, 150)];
        if (count($out['rows']) >= 3) break;
    }
    if (!is_dir(CACHE)) { @mkdir(CACHE, 0755, true); }
    @file_put_contents($f, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

function page(string $title, string $desc, string $url, string $body, array $ld = [], array $crumbs = []): void {
    $ld[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => NAME, 'url' => BASE . '/',
             'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/']];
    if ($crumbs) {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => NAME, 'item' => BASE . '/']];
        foreach ($crumbs as $i => [$n, $l]) { $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $n, 'item' => $l]; }
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '"><link rel="canonical" href="' . h($url) . '">';
    echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . h(NAME) . '"><meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:image" content="' . SITE . '/images/ogp/kseidocal.png"><meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image"><meta name="color-scheme" content="light">';
    echo '<link rel="icon" href="https://exbridge.jp/images/logo-mark-128.png"><link rel="alternate" type="text/calendar" title="' . h(NAME) . '" href="' . u('/cal.ics') . '">';
    foreach ($ld as $j) { echo '<script type="application/ld+json">' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    echo '<style>
:root{--navy:#1e2240;--ind:#4338ca;--ind-l:#eef0fd;--ink:#1f2933;--sub:#5b6676;--line:#dde1ea;--bg:#f7f7fb;--soon:#b45309;--done:#64748b;--hit:#fff7e6}
*{box-sizing:border-box}html{color-scheme:light}body{margin:0;background:#fff;color:var(--ink);font:15.5px/1.75 "Noto Sans JP","Hiragino Sans","Yu Gothic",system-ui,sans-serif}
a{color:var(--ind);overflow-wrap:anywhere}.wrap{max-width:980px;margin:0 auto;padding:0 16px;min-width:0}
header.top{border-bottom:1px solid var(--line);background:#fff}header.top .wrap{display:flex;align-items:center;gap:6px 16px;flex-wrap:wrap;min-height:58px;padding-top:6px;padding-bottom:6px}
.brand{display:flex;align-items:center;gap:10px;color:var(--navy);text-decoration:none;font-weight:800;font-size:16px;line-height:1.3}.brand img{width:34px;height:34px;object-fit:contain;flex:none}.brand small{display:block;color:var(--ind);font-size:11.5px;font-weight:700}
nav.menu{display:flex;flex-wrap:wrap;gap:2px 14px;font-size:14px;font-weight:700}nav.menu a{color:var(--sub);text-decoration:none}
.promo{margin-left:auto;display:flex;flex-wrap:wrap;gap:2px 12px;font-size:13px;font-weight:800}.promo a{color:#b45309;text-decoration:none}
main{padding:10px 0 40px}h1{font-size:clamp(22px,4vw,30px);line-height:1.4;color:var(--navy);margin:18px 0 8px;text-wrap:balance}
h2{font-size:19px;color:var(--navy);margin:30px 0 10px;border-left:5px solid var(--ind);padding-left:10px}h3{font-size:16px;color:var(--navy);margin:16px 0 6px}
.lead{color:var(--sub);margin:0 0 14px}.panel{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin:12px 0}
.row{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center}label{font-size:14.5px;font-weight:600;display:inline-flex;align-items:center;gap:6px}select{font:inherit;font-size:16px;padding:7px 10px;border:2px solid var(--line);border-radius:10px;background:#fff;max-width:100%}
.tl{list-style:none;margin:0;padding:0;border-left:3px solid var(--line);margin-left:8px}.tl li{position:relative;padding:4px 0 14px 18px}.tl li:before{content:"";position:absolute;left:-9px;top:12px;width:15px;height:15px;border-radius:50%;background:#fff;border:3px solid var(--ind)}
.tl li.past:before{border-color:var(--done)}.ev{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 14px}.tl li.hit .ev{background:var(--hit);border-color:#f3d79a}.tl li.off{opacity:.45}
.ev .d{font-size:13px;color:var(--sub);font-weight:700}.ev a.t{display:block;font-weight:800;color:var(--navy);text-decoration:none;font-size:16.5px;margin:2px 0}.ev .w{font-size:13.5px;color:var(--sub)}.mine[hidden]{display:none}.mine{display:inline-block;font-size:12px;font-weight:800;color:#fff;background:var(--soon);border-radius:999px;padding:0 9px;margin-left:6px}
.soon{color:var(--soon)}.done{color:var(--done)}.cat{display:inline-block;font-size:12px;font-weight:700;border-radius:999px;padding:0 8px;background:var(--ind-l);color:var(--ind);margin-right:6px}
.big{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:10px;margin:12px 0}.big div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px}.big .k{display:block;font-size:12.5px;color:var(--sub);font-weight:700}.big .v{display:block;font-size:22px;font-weight:800;color:var(--navy);line-height:1.35}.big .s{display:block;font-size:12.5px;color:var(--sub)}
.kk{border-left:4px solid var(--line);padding:4px 0 4px 12px;margin:10px 0;font-size:14.5px}.kk .m{font-size:13px;color:var(--sub)}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:10px}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;text-decoration:none;color:inherit;display:block}.card b{color:var(--navy)}.card p{margin:4px 0 0;font-size:14px;color:var(--sub)}
.btn{display:inline-block;background:var(--ind);color:#fff;border:0;border-radius:10px;padding:10px 18px;font-weight:700;text-decoration:none;font-size:15px}.btn.sub{background:#fff;color:var(--ind);border:2px solid var(--ind)}
.src{font-size:12.5px;color:var(--sub)}details{border-bottom:1px solid var(--line);padding:8px 0}summary{cursor:pointer;font-weight:700}
footer{margin-top:40px;border-top:1px solid var(--line);padding:18px 0;font-size:13px;color:var(--sub)}footer p{margin:6px 0}
.xshare{display:inline-flex;align-items:center;gap:8px;background:#0f1419;color:#fff;text-decoration:none;font-weight:800;font-size:14px;border-radius:999px;padding:8px 16px}
</style></head><body>';
    echo '<header class="top"><div class="wrap"><a class="brand" href="' . u('/') . '"><img src="https://exbridge.jp/images/logo-mark-128.png" width="34" height="34" alt="株式会社エクスブリッジ"><span>' . h(NAME) . '<small>会社に関係する制度の変更を、施行日の順に</small></span></a>';
    echo '<nav class="menu"><a href="' . u('/') . '">カレンダー</a><a href="' . u('/cal.ics') . '">カレンダーに入れる</a><a href="' . u('/about') . '">出典</a></nav>';
    if (($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp') {   // 当社の公開先だけ。配布版を置いたサイトには出さない
    echo '<!--kurage-only--><span class="promo"><a href="https://exbridge.jp/ai-it-komon.html?ref=kseidocal-head-komon" target="_blank" rel="noopener">AI-IT顧問</a><a href="https://kurage.exbridge.jp/vibe-customize.html?ref=kseidocal-head-vibe" target="_blank" rel="noopener">バイブカスタマイズ</a><a href="https://kurage.exbridge.jp/reseller.html?ref=kseidocal-head-reseller" target="_blank" rel="noopener">販売代理店募集</a></span><!--/kurage-only-->';
    }
    echo '</div></header><main class="wrap">' . $body . '</main>';
    echo '<footer><div class="wrap"><p>日付は各省庁の公式ページで確認したものです（' . jd(CHECKED) . '）。国会の発言は、国立国会図書館「国会会議録検索システム」の API から取っています（1日ごとに更新）。</p>';
    echo '<p>このページは制度の案内で、税務・労務・法律の助言ではありません。個別の判断は、税理士・社会保険労務士・弁護士や、各省庁の窓口にご相談ください。</p>';
    echo '<p><a href="https://exbridge.jp/?ref=kseidocal">株式会社エクスブリッジ</a>（名古屋・業務システム開発）・<a href="' . SITE . '/kchingin.php/?ref=kseidocal">最低賃金・賃上げ助成金チェック</a>・<a href="' . SITE . '/ktoriteki.php/?ref=kseidocal">取適法チェック</a>・<a href="https://xb4g.com/giin/tracker?ref=kseidocal">国会トラッカー</a></p></div></footer>';
    if (($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp') echo '<p><a href="https://kappstore.exbridge.jp/app.php?id=bb4980bad6219e25&amp;ref=kseidocal" rel="noopener">このページの一式を自社サイトに置く（商品ページ・ソースコード付き）</a></p>';
    if (($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp') {   // 計測も当社の公開先だけ
    echo '<img src="' . SITE . '/simpletrack.php?t=img&url=' . rawurlencode($url) . '&ref=' . rawurlencode((string)($_GET['ref'] ?? '')) . '" width="1" height="1" alt="" aria-hidden="true" style="position:absolute;left:-9999px">';
    }
    if (($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp') echo '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
    echo '</body></html>';
}

function faq(array $qa): array {
    $html = ''; $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
    foreach ($qa as [$q, $a]) { $html .= '<details><summary>' . h($q) . '</summary><p>' . h($a) . '</p></details>'; $ld['mainEntity'][] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]]; }
    return [$html, $ld];
}

function tool_cards(array $keys, string $ref): string {
    if (!$keys) return '';
    $s = '<div class="cards">';
    foreach ($keys as $k) { [$n, $l, $w, $free] = TOOLS[$k]; $s .= '<a class="card" href="' . h($l . (str_contains($l, '?') ? '&' : '?') . 'ref=' . $ref) . '"' . ($free ? '' : ' target="_blank" rel="noopener"') . '><b>' . h($n) . '</b><p>' . h($w) . '</p></a>'; }
    return $s . '</div>';
}

// ---------------- ルーティング ----------------
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && !str_ends_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/') && !str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '?')) { header('Location: ' . BASE . '/', true, 302); exit; }
if ($path === '/robots.txt') { header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nSitemap: " . u('/sitemap.xml') . "\n"; exit; }
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (array_merge(['/', '/about'], array_map(fn($i) => '/i/' . $i['slug'] . '/', ITEMS)) as $p) { echo '<url><loc>' . h(u($p)) . '</loc><lastmod>' . CHECKED . '</lastmod></url>'; }
    echo '</urlset>'; exit;
}
if ($path === '/cal.ics') {
    header('Content-Type: text/calendar; charset=UTF-8'); header('Content-Disposition: inline; filename="kseidocal.ics"');
    $e = static fn(string $s): string => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
    $l = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EXBRIDGE//kseidocal//JA', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:' . $e(NAME), 'X-WR-TIMEZONE:Asia/Tokyo'];
    foreach (ITEMS as $it) {
        $dates = isset($it['size']) ? array_filter($it['size']) : [$it['date']];
        foreach ($dates as $k => $d) {
            $ymd = str_replace('-', '', $d); $next = date('Ymd', strtotime($d . ' +1 day'));
            $sum = $it['name'] . (isset($it['size']) ? '（' . ['1' => '10人以下', '11' => '11〜20人', '21' => '21〜35人', '36' => '36〜50人'][$k] . '）' : '');
            $l = array_merge($l, ['BEGIN:VEVENT', 'UID:' . $it['slug'] . '-' . $ymd . '@kurage.exbridge.jp', 'DTSTAMP:' . gmdate('Ymd\THis\Z', strtotime(CHECKED)), 'DTSTART;VALUE=DATE:' . $ymd, 'DTEND;VALUE=DATE:' . $next,
                'SUMMARY:' . $e($sum), 'DESCRIPTION:' . $e($it['when'] . '。' . $it['what'] . ' ' . u('/i/' . $it['slug'] . '/')), 'URL:' . u('/i/' . $it['slug'] . '/'), 'END:VEVENT']);
        }
    }
    $l[] = 'END:VCALENDAR'; echo implode("\r\n", $l) . "\r\n"; exit;
}
if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    $l = ['# ' . NAME, '', '> 中小企業の経営者に関係する制度の変更（取適法・最低賃金・社会保険の適用拡大・カスタマーハラスメント対策・ストレスチェック・雇用保険・子ども子育て支援金・インボイス）を施行日の順に並べ、従業員数などから自社に関係するものと時期を出すサイト。項目ごとに国会での直近の議論・会社がやること・公式の出典。株式会社エクスブリッジ製。', '', '## 項目（施行日・期限）'];
    foreach (ITEMS as $it) { $l[] = '- ' . $it['name'] . '：' . $it['when'] . '（出典 ' . $it['src'][1] . '）'; }
    $l = array_merge($l, ['', '## ページ', '- カレンダー: ' . u('/'), '- iCalendar: ' . u('/cal.ics'), '- 項目ごと: ' . u('/i/toriteki/') . ' など']);
    echo implode("\n", $l) . "\n"; exit;
}
$seg = array_values(array_filter(explode('/', $path), fn($x) => $x !== ''));

if (($seg[0] ?? '') === 'i' && isset($seg[1]) && ($it = item($seg[1]))) {
    $url = u('/i/' . $it['slug'] . '/');
    $kk = kokkai($it['kokkai']);
    $body = '<p class="src"><a href="' . u('/') . '">← カレンダーへ</a></p><h1>' . h($it['name']) . '</h1>';
    $body .= '<div class="big"><div><span class="k">いつから</span><span class="v" style="font-size:18px">' . h($it['when']) . '</span><span class="s">' . (isset($it['size']) ? '36〜50人の会社は' . status($it['date']) : status($it['date'])) . '</span></div><div><span class="k">分野</span><span class="v" style="font-size:18px">' . h($it['cat']) . '</span><span class="s">出典：<a href="' . h($it['src'][1]) . '" rel="noopener">' . h($it['src'][0]) . '</a></span></div></div>';
    $body .= '<h2>何が変わるか</h2><p>' . h($it['what']) . '</p>';
    $body .= '<h2>会社がやること</h2><ol>' . implode('', array_map(fn($t) => '<li>' . h($t) . '</li>', $it['todo'])) . '</ol>';
    if ($kk && !empty($kk['rows'])) {
        $body .= '<h2>国会での直近の議論</h2><p class="src">「' . h($it['kokkai']) . '」に触れた発言は、国会会議録に' . number_format($kk['total']) . '件あります。新しいものから3件。</p>';
        foreach ($kk['rows'] as $r) { $body .= '<div class="kk"><div class="m">' . h(jd($r['date'])) . '・' . h($r['house'] . ' ' . $r['meeting']) . '・' . h($r['speaker']) . '</div>「…' . h($r['text']) . '…」 <a href="' . h($r['url']) . '" rel="noopener">発言の全文</a></div>'; }
    }
    if ($it['tools']) { $body .= '<h2>役に立つもの</h2>' . tool_cards($it['tools'], 'kseidocal-' . $it['slug']); }
    $body .= '<p style="margin-top:16px"><a class="btn sub" href="https://exbridge.jp/ai-it-komon.html?ref=kseidocal-' . h($it['slug']) . '" target="_blank" rel="noopener">制度の変更にあわせて業務を見直す（AI-IT顧問）</a></p>';
    $q = http_build_query(['text' => $it['name'] . '（' . $it['when'] . '）。会社がやることと国会での議論をまとめています', 'url' => $url . '?ref=x-share-kseidocal']);
    $body .= '<p><a class="xshare" href="https://x.com/intent/post?' . h($q) . '" target="_blank" rel="noopener">𝕏 このページをXで共有</a></p>';
    $title = $it['seo'];
    $desc = mb_substr($it['when'] . '。' . $it['what'] . '会社がやること：' . $it['todo'][0] . '。', 0, 118);
    [$fh, $fl] = faq([[$it['name'] . 'はいつからですか？', $it['when'] . '。'], ['会社は何をすればよいですか？', implode('。', $it['todo']) . '。']]);
    $body .= '<h2>よくある質問</h2>' . $fh;
    page($title, $desc, $url, $body, [$fl], [[$it['name'], $url]]);
    exit;
}

if (($seg[0] ?? '') === 'about') {
    $url = u('/about');
    $r = ''; foreach (ITEMS as $it) { $r .= '<li>' . h($it['name']) . '：<a href="' . h($it['src'][1]) . '" rel="noopener">' . h($it['src'][0]) . '</a></li>'; }
    $body = '<h1>出典としないこと</h1><h2>出典</h2><ul>' . $r . '<li>国会の発言：国立国会図書館「国会会議録検索システム」API</li></ul><p>' . jd(CHECKED) . 'に確認しました。</p><h2>しないこと</h2><p>税務・労務・法律の助言はしません。自社が対象か・何をするかの最終的な判断は、税理士・社会保険労務士・弁護士や各省庁の窓口へ。選んだ従業員数などはブラウザの中だけで使い、送信・保存しません。</p>';
    page('出典としないこと｜' . NAME, '経営者の制度カレンダーに載せた制度の変更（取適法・最低賃金・社会保険の適用拡大・カスハラ対策・ストレスチェック・雇用保険・子ども子育て支援金・インボイス）の、省庁の公式ページの出典一覧。', $url, $body, [], [['出典', $url]]);
    exit;
}

if ($seg) { http_response_code(404); page('見つかりません｜' . NAME, '', BASE . '/', '<h1>見つかりません</h1><p><a class="btn" href="' . u('/') . '">カレンダーへ</a></p>'); exit; }

// トップ：施行日の順のタイムライン。従業員数などを選ぶと、自社に関係するものに印を付ける
$items = ITEMS; usort($items, fn($a, $b) => strcmp($a['date'], $b['date']));
$next = null; foreach ($items as $it) { if (days($it['date']) >= 0) { $next = $it; break; } }
$li = '';
foreach ($items as $it) {
    $past = days($it['date']) < 0;
    $li .= '<li class="' . ($past ? 'past' : '') . '" data-need="' . h(implode(' ', $it['need'])) . '" data-size="' . h(isset($it['size']) ? json_encode($it['size']) : '') . '" data-slug="' . h($it['slug']) . '"><div class="ev"><div class="d"><span class="cat">' . h($it['cat']) . '</span><span class="dt">' . h(jd($it['date'])) . '</span>　<span class="st">' . status($it['date']) . '</span><span class="mine" hidden>あなたの会社に関係</span></div>'
         . '<a class="t" href="' . u('/i/' . $it['slug'] . '/') . '">' . h($it['name']) . '</a><div class="w">' . h($it['when']) . '</div></div></li>';
}
$body = '<h1>経営者の制度カレンダー：会社に関係する制度の変更を、施行日の順に</h1>';
$body .= '<p class="lead">取適法、最低賃金、社会保険の適用拡大、カスタマーハラスメント対策、ストレスチェック、雇用保険、子ども・子育て支援金、インボイス。会社に降ってくる制度の変更を、施行日の順に並べました。従業員数などを選ぶと、自社に関係するものと、いつからかが分かります。</p>';
$body .= '<div class="big"><div><span class="k">次に来るもの</span><span class="v" style="font-size:17px">' . ($next ? h($next['name']) : '—') . '</span><span class="s">' . ($next ? h(jd($next['date'])) . '・' . status($next['date']) : '') . '</span></div><div><span class="k">載せている制度</span><span class="v">' . count(ITEMS) . '件</span><span class="s">日付は省庁の公式ページで確認</span></div><div><span class="k">カレンダーアプリに</span><span class="v" style="font-size:17px"><a href="' . u('/cal.ics') . '">iCalendar で入れる</a></span><span class="s">Google・iPhone・Outlook</span></div></div>';
$body .= '<div class="panel" id="me"><div class="row"><label for="size">厚生年金の被保険者数</label><select id="size"><option value="">選ばない</option><option value="1">1〜10人</option><option value="11">11〜20人</option><option value="21">21〜35人</option><option value="36">36〜50人</option><option value="51">51人以上</option></select></div>'
    . '<div class="row" style="margin-top:8px"><label><input type="checkbox" id="part" checked> パート・アルバイトがいる</label><label><input type="checkbox" id="sub" checked> 外注・下請けに発注している／受けている</label><label><input type="checkbox" id="face" checked> お客さまと接する仕事がある</label><label><input type="checkbox" id="inv2"> インボイスの2割特例を使っている</label></div>'
    . '<p class="src" style="margin:8px 0 0">選んだ内容はこの画面の中だけで使い、送信しません。</p></div>';
$body .= '<ul class="tl" id="tl">' . $li . '</ul>';
$body .= '<p class="src">日付は施行日（期限の項目は期限）。社会保険の適用拡大（企業規模）は、従業員数を選ぶと自社の年月に変わります。</p>';
$body .= '<h2>計算と点検</h2>' . tool_cards(['kchingin', 'ktoriteki'], 'kseidocal-top');
$q = http_build_query(['text' => '会社に関係する制度の変更（取適法・最低賃金・社会保険の適用拡大・カスハラ対策・インボイス…）を施行日の順に。従業員数を選ぶと自社に関係するものが分かります', 'url' => BASE . '/?ref=x-share-kseidocal']);
$body .= '<p style="margin-top:14px"><a class="xshare" href="https://x.com/intent/post?' . h($q) . '" target="_blank" rel="noopener">𝕏 このページをXで共有</a></p>';
[$fh, $fl] = faq([
    ['2026年に中小企業に関係する制度の変更は？', '2026年は、1月に取適法（下請法の改正）、4月分の保険料から子ども・子育て支援金、9月30日を含む課税期間でインボイスの2割特例の終了、10月に最低賃金の引上げ（都道府県ごと）、社会保険の賃金要件の撤廃、カスタマーハラスメント対策の義務化があります。'],
    ['社会保険の適用拡大は、うちの会社はいつからですか？', '厚生年金保険の被保険者数で決まります。36〜50人は2027年10月、21〜35人は2029年10月、11〜20人は2032年10月、10人以下は2035年10月から、パート・アルバイトの加入の対象が広がります。51人以上はすでに対象です。'],
    ['カレンダーアプリに入れられますか？', 'はい。iCalendar（.ics）を用意しています。Google カレンダーや iPhone のカレンダーに、URL で追加できます。'],
]);
$body .= '<h2>よくある質問</h2>' . $fh;
$body .= <<<'JS'
<script>
(function(){
var S=document.getElementById('size');
function jd(s){var a=s.split('-');return (+a[0])+'年'+(+a[1])+'月'+(+a[2])+'日'}
function left(s){var d=Math.round((new Date(s+'T00:00:00+09:00')-new Date(new Date().toDateString()))/86400000);return d>0?'<b class="soon">あと'+d.toLocaleString('ja-JP')+'日</b>':(d===0?'<b class="soon">今日から</b>':'<span class="done">施行済み（'+(-d).toLocaleString('ja-JP')+'日前）</span>')}
function run(){
  var sz=S.value,on={emp:true,lt50:sz!=='51',part:document.getElementById('part').checked,sub:document.getElementById('sub').checked,face:document.getElementById('face').checked,inv2:document.getElementById('inv2').checked};
  document.querySelectorAll('#tl li').forEach(function(li){
    var need=li.dataset.need.split(' '),hit=need.every(function(n){return on[n]}),m=li.querySelector('.mine'),size=li.dataset.size?JSON.parse(li.dataset.size):null;
    if(size&&sz){var d=size[sz];if(d===null){li.querySelector('.dt').textContent='すでに対象';li.querySelector('.st').innerHTML='<span class="done">51人以上は加入の対象</span>';}else{li.querySelector('.dt').textContent=jd(d);li.querySelector('.st').innerHTML=left(d)}}
    li.classList.toggle('hit',hit);li.classList.toggle('off',!hit);m.hidden=!hit;
  });
}
['size','part','sub','face','inv2'].forEach(function(id){document.getElementById(id).addEventListener('change',run)});
run();
})();
</script>
JS;
page('経営者の制度カレンダー｜2026年からの制度変更と施行日', '取適法・最低賃金・社会保険の適用拡大（106万円の壁）・カスハラ対策の義務化・ストレスチェック・雇用保険・子ども子育て支援金・インボイスの2割特例の終了を施行日の順に。従業員数を選ぶと自社に関係するものと時期が分かります。', BASE . '/', $body, [$fl]);
