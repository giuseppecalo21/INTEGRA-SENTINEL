<?php
/**
 * Integra Sentinel — documenti per i clienti, con il marchio dell'agenzia:
 * report mensile di manutenzione, audit di presa in carico e richieste al cliente / all'hosting.
 * Pagine HTML stampabili (Stampa → Salva come PDF) generate interamente dal Master.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Reports {

    private static function e($value) { return esc_html((string) $value); }

    /** "2026-10" → inizio e fine del mese nel fuso orario di WordPress. */
    public static function month_bounds($ym) {
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $ym, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            $ym = wp_date('Y-m', strtotime('first day of last month', current_time('timestamp')));
            preg_match('/^(\d{4})-(\d{2})$/', $ym, $m);
        }
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $m[1], $m[2]), wp_timezone());
        $end = $start->modify('+1 month');
        // I documenti sono in italiano anche se il Master usa un'altra lingua o non ha le traduzioni installate.
        $months = array(1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre');
        return array('ym' => $start->format('Y-m'), 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'label' => $months[(int) $start->format('n')] . ' ' . $start->format('Y'));
    }

    private static function shell($title, $subtitle, $body) {
        $b = Integra_Sentinel_Settings::get('branding');
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) $b['color']) ? $b['color'] : '#0c8997';
        $logo = !empty($b['logo']) ? '<img src="' . esc_url($b['logo']) . '" alt="' . esc_attr($b['name']) . '" style="max-height:44px;max-width:200px">' : '<strong style="font-size:20px">' . self::e($b['name']) . '</strong>';
        return '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($title . ' · ' . $subtitle) . '</title><style>'
            . ':root{--c:' . $color . '}*{box-sizing:border-box}body{margin:0;background:#eef2f6;color:#1d2b3a;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}'
            . '.page{max-width:880px;margin:24px auto;background:#fff;border:1px solid #dbe3ea;border-radius:10px;overflow:hidden}'
            . 'header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 30px;border-bottom:4px solid var(--c)}'
            . 'header .meta{text-align:right;font-size:12px;color:#5c6e80}main{padding:8px 30px 26px}h1{font-size:25px;margin:22px 0 4px;letter-spacing:-.4px}'
            . 'h2{font-size:16px;margin:26px 0 10px;padding-bottom:6px;border-bottom:1px solid #e3e9ef}.sub{color:#5c6e80;margin:0 0 14px}'
            . '.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:16px 0}.kpi{border:1px solid #e1e8ee;border-radius:8px;padding:12px;background:#f8fafc}'
            . '.kpi b{display:block;font-size:24px;color:var(--c);line-height:1.2}.kpi span{font-size:11.5px;color:#5c6e80}'
            . 'table{width:100%;border-collapse:collapse;font-size:12.5px}th,td{text-align:left;padding:7px 8px;border-bottom:1px solid #edf1f5;vertical-align:top}th{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#6b7c8d}'
            . '.pill{display:inline-block;font-size:11px;font-weight:700;padding:2px 7px;border-radius:4px;background:#eef3f7;color:#3f5568}.ok{background:#e8f7f0;color:#14724f}.warn{background:#fff6e2;color:#8a5d0b}.bad{background:#fdecee;color:#a11f35}'
            . '.story{font-size:14.5px;line-height:1.65;margin:8px 0 6px}.story h3:first-child{margin-top:10px}'
            . '.note{background:#f6f9fb;border-left:3px solid var(--c);padding:10px 14px;margin:12px 0;color:#2e4356}'
            . '.verdict{display:flex;flex-direction:column;gap:3px;border-radius:8px;padding:14px 16px;margin:14px 0 6px;border:1px solid #cfe9dd;background:#eefaf4}.verdict strong{font-size:16px;color:#14724f}.verdict span{color:#2e4356}'
            . '.verdict.warn{border-color:#f1dfb4;background:#fffaf0}.verdict.warn strong{color:#8a5d0b}.verdict.bad{border-color:#f3c9d0;background:#fdf2f4}.verdict.bad strong{color:#a11f35}'
            . 'ul.checks{list-style:none;padding:0}ul.checks li{position:relative;padding-left:22px;margin:5px 0}ul.checks li:before{content:"✓";position:absolute;left:2px;color:var(--c);font-weight:700}ul{padding-left:20px}li{margin:3px 0}'
            . 'footer{padding:16px 30px;background:#f6f8fa;border-top:1px solid #e3e9ef;font-size:11px;color:#6b7c8d}.toolbar{max-width:880px;margin:16px auto 0;text-align:right}'
            . '.toolbar button{font:inherit;padding:8px 14px;border:1px solid #c9d4de;background:#fff;border-radius:6px;cursor:pointer}'
            . '@media(max-width:700px){.kpis{grid-template-columns:repeat(2,1fr)}header{flex-direction:column;align-items:flex-start}header .meta{text-align:left}main,header,footer{padding-left:16px;padding-right:16px}}'
            . '@media print{body{background:#fff}.toolbar{display:none}.page{border:0;margin:0;max-width:none}h2{break-after:avoid}tr{break-inside:avoid}}'
            . '</style></head><body><div class="toolbar"><button type="button" onclick="window.print()">Stampa o salva in PDF</button></div><div class="page"><header><div>' . $logo . '</div><div class="meta"><strong>' . self::e($title) . '</strong><br>' . self::e($subtitle) . '</div></header><main>'
            . $body . '</main><footer>' . self::e($b['footer'] ?: ($b['name'] . ' · ' . $b['url'])) . '<br>Le verifiche di sicurezza riguardano vulnerabilità note nei database consultati e i controlli di integrità sui file principali: non sostituiscono un audit completo.</footer></div></body></html>';
    }

    private static function pill($text, $tone) {
        return '<span class="pill ' . esc_attr($tone) . '">' . self::e($text) . '</span>';
    }

    /**
     * Report mensile: cosa abbiamo fatto, cosa abbiamo sorvegliato, cosa resta aperto e chi deve intervenire.
     * E' il documento che giustifica il canone e mette per iscritto le responsabilita' di ciascuno.
     */
    public static function monthly_html($site, $ym, $draft_preview = false) {
        $period = self::month_bounds($ym);
        $id = (string) $site['id'];
        $updates = self::month_updates($site, $period);
        $closed = self::closed_summary($updates);
        $installed = Integra_Sentinel_Store::events_between($period['start'], $period['end'], $id, array('install', 'remove'), 500);
        $uptime = Integra_Sentinel_Store::uptime($id, $period['start'], $period['end']);
        $incidents = self::incidents($id, $period['start'], $period['end']);
        $cache = (array) get_option(Integra_Sentinel_Master::FEEDS, array());
        $m = Integra_Sentinel_Master::sn_metrics($site, $cache);
        $a = Integra_Sentinel_Analysis::site($site);
        $r = (array) ($site['report'] ?? array());
        $integrity = count((array) ($site['integrity_findings'] ?? array()));
        $points = self::open_points($site, $a);
        $backup = self::backup_state($site, $period, $a);
        $b = Integra_Sentinel_Settings::get('branding');
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) $b['color']) ? $b['color'] : '#0c8997';

        $html = '<h1>Report di manutenzione</h1><p class="sub">' . self::e($site['label']) . ' · ' . self::e($site['url']) . ' · ' . self::e(ucfirst($period['label'])) . '</p>';
        $verdict = self::verdict($m, $integrity, $points, $backup['tone']);
        $html .= '<div class="verdict ' . esc_attr($verdict['tone']) . '"><strong>' . self::e($verdict['title']) . '</strong><span>' . self::e($verdict['text']) . '</span></div>';
        // Resoconto scritto per il cliente: entra nel report solo dopo l'approvazione (l'anteprima mostra anche le bozze).
        $draft = Integra_Sentinel_Store::draft_get($id, $period['ym']);
        if ($draft && trim((string) $draft['body']) !== '' && ($draft_preview || in_array($draft['status'], array('approved', 'sent'), true))) {
            if ($draft['status'] === 'draft') { $html .= '<p class="note" style="border-left-color:#c58a12">Anteprima di una bozza non ancora approvata: il cliente non la vede finché non viene approvata.</p>'; }
            $html .= '<div class="story">' . Integra_Sentinel_AI::to_html($draft['body'], $color) . '</div><h2>I numeri del mese</h2>';
        }
        $html .= '<div class="kpis"><div class="kpi"><b>' . count($updates) . '</b><span>' . (count($updates) === 1 ? 'aggiornamento applicato' : 'aggiornamenti applicati') . '</span></div>'
            . '<div class="kpi"><b>' . (int) $closed['count'] . '</b><span>' . ((int) $closed['count'] === 1 ? 'falla di sicurezza nota chiusa' : 'falle di sicurezza note chiuse') . '</span></div>'
            . '<div class="kpi"><b>' . ($uptime ? self::e(number_format_i18n($uptime['pct'], 2)) . '%' : 'n.d.') . '</b><span>disponibilità del sito</span></div>'
            . '<div class="kpi"><b>' . (int) $m['affected'] . '</b><span>' . ((int) $m['affected'] === 1 ? 'componente con vulnerabilità note oggi' : 'componenti con vulnerabilità note oggi') . '</span></div></div>';
        $parts = array();
        $parts[] = count($updates) ? (count($updates) === 1 ? 'Nel mese abbiamo applicato 1 aggiornamento.' : 'Nel mese abbiamo applicato ' . count($updates) . ' aggiornamenti tra WordPress, plugin e temi.') : 'Nel mese non sono stati registrati aggiornamenti di versione.';
        if ($closed['count']) {
            $subject = count($updates) === 1 ? 'L’aggiornamento ha chiuso ' : ($closed['count'] === 1 ? 'Uno di questi ha chiuso ' : 'Gli aggiornamenti hanno chiuso ');
            $parts[] = $subject . ($closed['count'] === 1 ? 'una falla di sicurezza nota (gravità ' : $closed['count'] . ' falle di sicurezza note (gravità massima: ') . strtolower(Integra_Sentinel_Master::sn_severity($closed['top'])) . ').';
            if ($closed['avg_days'] !== null) {
                $parts[] = $closed['timed'] === 1 ? 'Tra la rilevazione della falla da parte del nostro monitoraggio e l’aggiornamento sono passati ' . self::days_text($closed['avg_days']) . '.'
                    : 'Per le falle già segnalate al momento dell’aggiornamento sono passati in media ' . self::days_text($closed['avg_days']) . ' da quando il nostro monitoraggio le ha rilevate.';
            }
        }
        if ($uptime) { $parts[] = 'Il sito ha risposto correttamente nel ' . number_format_i18n($uptime['pct'], 2) . '% dei ' . number_format_i18n((int) $uptime['checks']) . ' controlli (tempo medio ' . (int) $uptime['ms'] . ' ms).'; }
        $parts[] = $m['affected'] ? ((int) $m['affected'] === 1 ? 'Resta 1 componente con vulnerabilità note, indicato qui sotto.' : 'Restano ' . (int) $m['affected'] . ' componenti con vulnerabilità note, indicati qui sotto.') : 'Ad oggi non risultano vulnerabilità note applicabili alle versioni installate.';
        $html .= '<div class="note">' . self::e(implode(' ', $parts)) . '</div>';

        $watch = self::watch($site, $period, $a, $backup);
        if ($watch) {
            $html .= '<h2>La sorveglianza del mese</h2><p class="sub">Il lavoro che non si vede: controlli eseguiti sul sito anche quando non c’era niente da aggiornare.</p><ul class="checks">';
            foreach ($watch as $line) { $html .= '<li>' . self::e($line) . '</li>'; }
            $html .= '</ul>';
        }

        $html .= '<h2>Aggiornamenti applicati</h2>';
        if (!$updates) { $html .= '<p class="sub">Nessun cambio di versione registrato nel periodo.</p>'; }
        else {
            $with_fixes = (bool) array_filter(array_column($updates, 'fixed'));
            $html .= '<table><thead><tr><th>Data</th><th>Componente e versione</th>' . ($with_fixes ? '<th>Sicurezza</th>' : '') . '</tr></thead><tbody>';
            foreach (array_reverse($updates) as $u) {
                $e = $u['event'];
                $sec = '';
                if ($u['fixed']) {
                    $n = count($u['fixed']);
                    $tier = self::top_tier(array_column($u['fixed'], 'tier'));
                    $sec = self::pill($n === 1 ? 'Chiude 1 falla' : 'Chiude ' . $n . ' falle', in_array($tier, array('critical', 'high'), true) ? 'bad' : 'warn') . '<br><small style="color:#5c6e80">gravità ' . self::e(strtolower(Integra_Sentinel_Master::sn_severity($tier))) . '</small>';
                }
                $html .= '<tr><td>' . self::e(wp_date('d/m/Y', (int) $e['at'])) . '</td><td>' . self::e($e['message']) . (($e['flags'] ?? '') === 'auto' ? ' <small style="color:#5c6e80">(automatico)</small>' : '')
                    . (trim((string) ($e['note'] ?? '')) !== '' ? '<br><small style="color:#5c6e80">' . self::e($e['note']) . '</small>' : '') . '</td>' . ($with_fixes ? '<td>' . $sec . '</td>' : '') . '</tr>';
            }
            $html .= '</tbody></table>';
        }
        if ($installed) {
            $html .= '<h2>Plugin e temi aggiunti o rimossi</h2><ul>';
            foreach (array_reverse($installed) as $e) { $html .= '<li>' . self::e(wp_date('d/m/Y', (int) $e['at']) . ' · ' . $e['message']) . (trim((string) ($e['note'] ?? '')) !== '' ? ' <small style="color:#5c6e80">· ' . self::e($e['note']) . '</small>' : '') . '</li>'; }
            $html .= '</ul>';
        }
        $tasks = self::tasks_done($id, $period);
        if ($tasks) {
            $minutes = array_sum(array_column($tasks, 'minutes'));
            $html .= '<h2>Interventi completati</h2><table><thead><tr><th>Chiuso il</th><th>Intervento</th>' . ($minutes ? '<th>Tempo</th>' : '') . '</tr></thead><tbody>';
            foreach ($tasks as $t) { $html .= '<tr><td>' . self::e(wp_date('d/m/Y', $t['closed_at'])) . '</td><td>' . self::e($t['title']) . '</td>' . ($minutes ? '<td>' . ($t['minutes'] ? self::e(self::minutes_text($t['minutes'])) : '—') . '</td>' : '') . '</tr>'; }
            $html .= '</tbody></table>' . ($minutes ? '<p class="sub">Tempo dedicato agli interventi registrati: <strong>' . self::e(self::minutes_text($minutes)) . '</strong>.</p>' : '');
        }

        $html .= '<h2>Backup</h2><p>' . self::pill($backup['value'], $backup['tone'] === 'ok' ? 'ok' : ($backup['tone'] === 'danger' ? 'bad' : 'warn')) . ' ' . self::e($backup['hint']) . '</p>';
        if ($backup['rows']) {
            $html .= '<table><thead><tr><th>Data</th><th>Backup</th><th>Esito</th></tr></thead><tbody>';
            foreach ($backup['rows'] as $row) { $html .= '<tr><td>' . self::e(wp_date('d/m/Y', $row['at'])) . '</td><td>' . self::e($row['label']) . ($row['detail'] !== '' ? '<br><small style="color:#5c6e80">' . self::e($row['detail']) . '</small>' : '') . '</td><td>' . ($row['ok'] ? self::pill('Riuscito', 'ok') : self::pill('Non riuscito', 'bad')) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        if ($backup['restore']) { $html .= '<div class="note">Il ' . self::e(wp_date('d/m/Y', $backup['restore'])) . ' abbiamo eseguito una prova di ripristino riuscita: il backup non è solo presente, è anche utilizzabile.</div>'; }

        if ($points) {
            $owners = self::owner_labels();
            $html .= '<h2>Punti aperti e chi deve intervenire</h2><p class="sub">Ciò che oggi impedisce di tenere il sito del tutto aggiornato, con chi può risolverlo e da quando è aperto.</p>';
            $html .= '<table><thead><tr><th>Punto aperto</th><th>A carico di</th><th>Aperto dal</th></tr></thead><tbody>';
            foreach ($points as $p) {
                $flags = ($p['security'] ? self::pill('Trattiene correzioni di sicurezza', 'bad') . ' ' : '') . ($p['agreed'] !== '' ? self::pill('Scelta concordata', '') : '');
                $html .= '<tr><td style="width:56%"><strong>' . self::e($p['title']) . '</strong>' . ($flags !== '' ? '<br>' . $flags : '')
                    . ($p['agreed'] !== '' ? '<br><small style="color:#5c6e80">Motivo: ' . self::e($p['agreed']) . ($p['until'] !== '' ? ' · fino al ' . self::e(wp_date('d/m/Y', strtotime($p['until'] . ' 12:00:00'))) : '') . '</small>'
                        : ($p['detail'] !== '' ? '<br><small style="color:#5c6e80">' . self::e($p['detail']) . '</small>' : ''))
                    . '</td><td>' . self::e($owners[$p['owner']] ?? $p['owner']) . '</td><td>' . self::e(self::since_text($p)) . '</td></tr>';
            }
            $html .= '</tbody></table>';
            if (array_filter($points, function($p) { return $p['security'] && $p['owner'] !== 'agenzia' && $p['agreed'] === ''; })) {
                $html .= '<div class="note" style="border-left-color:#a11f35">Finché i punti segnati “Trattiene correzioni di sicurezza” restano aperti, il sito resta esposto alle falle che quegli aggiornamenti correggerebbero. Noi continuiamo a sorvegliarli e li riportiamo in ogni report finché non saranno risolti.</div>';
            }
        }

        $deadlines = self::deadlines($site, $a);
        if ($deadlines) {
            $owners = self::owner_labels();
            $html .= '<h2>Scadenze in arrivo</h2><table><thead><tr><th>Cosa</th><th>Quando</th><th>A carico di</th></tr></thead><tbody>';
            foreach ($deadlines as $d) {
                $html .= '<tr><td>' . self::e($d['what']) . '</td><td>' . self::pill($d['days'] < 0 ? 'Scaduto il ' . wp_date('d/m/Y', $d['at']) : wp_date('d/m/Y', $d['at']) . ' · tra ' . $d['days'] . ($d['days'] === 1 ? ' giorno' : ' giorni'), $d['days'] < 0 ? 'bad' : ($d['days'] <= 30 ? 'warn' : '')) . '</td><td>' . self::e($owners[$d['owner']] ?? '—') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $notes = self::site_component_notes($site);
        if ($notes) {
            $html .= '<h2>Situazione di alcuni componenti</h2><table><thead><tr><th>Componente</th><th>Nota</th></tr></thead><tbody>';
            foreach ($notes as $n) { $html .= '<tr><td style="width:34%"><strong>' . self::e($n['componente']) . '</strong><br><small style="color:#5c6e80">' . self::e(ucfirst($n['tipo']) . ($n['versione_installata'] !== '' ? ' · versione ' . $n['versione_installata'] : '')) . '</small></td><td>' . self::e($n['nota']) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        $html .= '<h2>Sicurezza e stato tecnico</h2><table><tbody>';
        $html .= '<tr><td>Monitoraggio vulnerabilità</td><td>' . ($m['affected'] ? self::pill($m['affected'] . ((int) $m['affected'] === 1 ? ' componente da correggere' : ' componenti da correggere'), 'bad') : self::pill('Nessuna vulnerabilità nota', 'ok')) . '</td></tr>';
        $html .= '<tr><td>Integrità dei file e amministratori</td><td>' . ($integrity ? self::pill($integrity . ($integrity === 1 ? ' anomalia in verifica' : ' anomalie in verifica'), 'bad') : self::pill('Nessuna anomalia', 'ok')) . '</td></tr>';
        if (!empty($r['wp'])) { $html .= '<tr><td>WordPress</td><td>' . self::e($r['wp']) . (!empty($r['core_update']) ? ' · disponibile ' . self::e($r['core_update']) : ' · aggiornato') . '</td></tr>'; }
        if (!empty($r['php'])) { $html .= '<tr><td>PHP</td><td>' . self::e(Integra_Sentinel_EOL::sentence('php', $r['php'])) . '</td></tr>'; }
        if (isset($site['ssl_days_left']) && is_int($site['ssl_days_left'])) { $html .= '<tr><td>Certificato HTTPS</td><td>' . ($site['ssl_days_left'] < 14 ? self::pill('Scade tra ' . $site['ssl_days_left'] . ' giorni', 'warn') : self::pill('Valido (' . $site['ssl_days_left'] . ' giorni)', 'ok')) . '</td></tr>'; }
        $html .= '</tbody></table>';
        if ($incidents) {
            $html .= '<h2>Interruzioni rilevate</h2><table><thead><tr><th>Inizio</th><th>Durata stimata</th><th>Dettaglio</th></tr></thead><tbody>';
            foreach ($incidents as $i) { $html .= '<tr><td>' . self::e(wp_date('d/m/Y H:i', $i['from'])) . '</td><td>' . self::e(self::duration($i['to'] - $i['from'])) . '</td><td>' . self::e($i['error']) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        return self::shell('Report di manutenzione', $site['label'] . ' · ' . ucfirst($period['label']), $html);
    }

    /* ---------- Dati del mese condivisi tra report e resoconto dell'IA ---------- */

    /** Nome visualizzato → slug, per ritrovare i feed dei componenti citati negli eventi. */
    private static function names_to_slugs($site) {
        $r = (array) ($site['report'] ?? array());
        $by_name = array('plugin' => array(), 'theme' => array());
        foreach ((array) ($r['plugins'] ?? array()) as $p) { $by_name['plugin'][(string) $p['name']] = (string) $p['slug']; }
        foreach ((array) ($r['themes'] ?? array()) as $t) { $by_name['theme'][(string) $t['name']] = (string) $t['slug']; }
        return $by_name;
    }

    /**
     * Aggiornamenti del mese, dal piu' vecchio, con le falle che hanno chiuso secondo i feed.
     * Ogni voce: event, name, type (core | plugin | theme | ''), slug, from, to, fixed (elenco o null se sconosciuto/nessuna).
     */
    public static function month_updates($site, $period) {
        $by_name = self::names_to_slugs($site);
        $out = array();
        foreach (array_reverse(Integra_Sentinel_Store::events((string) $site['id'], $period['start'], $period['end'], 1000)) as $e) {
            if (!in_array($e['type'], array('update', 'core'), true)) { continue; }
            $item = array('event' => $e, 'name' => '', 'type' => '', 'slug' => '', 'from' => '', 'to' => '', 'fixed' => null);
            if (preg_match('/^(?:\[MU\] )?(.+): (\S*) → (\S*)$/u', (string) $e['message'], $mm)) {
                $type = $e['type'] === 'core' ? 'core' : (isset($by_name['plugin'][$mm[1]]) ? 'plugin' : (isset($by_name['theme'][$mm[1]]) ? 'theme' : ''));
                $item = array_merge($item, array('name' => $mm[1], 'type' => $type, 'slug' => in_array($type, array('plugin', 'theme'), true) ? $by_name[$type][$mm[1]] : '', 'from' => $mm[2], 'to' => $mm[3]));
                if ($type !== '') { $item['fixed'] = Integra_Sentinel_Master::advisories_fixed($type, $item['slug'], $mm[2], $mm[3]) ?: null; }
            }
            $out[] = $item;
        }
        return $out;
    }

    private static function top_tier($tiers) {
        foreach (array('critical', 'high', 'medium', 'low') as $tier) { if (in_array($tier, (array) $tiers, true)) { return $tier; } }
        return 'unknown';
    }

    /**
     * Falle chiuse dagli aggiornamenti del mese: numero, gravita' massima e giorni medi tra la prima rilevazione
     * di Sentinel e l'aggiornamento (solo per le falle con data nota e gia' rilevate prima dell'aggiornamento).
     */
    public static function closed_summary($updates) {
        $count = 0; $tiers = array(); $days = array();
        foreach ($updates as $u) {
            foreach ((array) $u['fixed'] as $f) {
                ++$count;
                $tiers[] = $f['tier'];
                if ($f['first_seen'] > 0 && $f['first_seen'] <= (int) $u['event']['at']) { $days[] = ((int) $u['event']['at'] - $f['first_seen']) / DAY_IN_SECONDS; }
            }
        }
        return array('count' => $count, 'top' => self::top_tier($tiers), 'avg_days' => $days ? round(array_sum($days) / count($days), 1) : null, 'timed' => count($days));
    }

    private static function days_text($days) {
        if ($days < 1) { return 'meno di un giorno'; }
        $n = (int) round($days);
        return $n === 1 ? '1 giorno' : $n . ' giorni';
    }

    private static function minutes_text($minutes) {
        $minutes = (int) $minutes;
        if ($minutes < 60) { return $minutes . ' min'; }
        return floor($minutes / 60) . ' h' . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '');
    }

    /** Interventi chiusi nel mese: title, notes, minutes, closed_at. */
    private static function tasks_done($site_id, $period) {
        $out = array();
        foreach (Integra_Sentinel_Store::tasks(array('site_id' => $site_id, 'status' => 'done', 'limit' => 300)) as $t) {
            if ((int) $t['closed_at'] < $period['start'] || (int) $t['closed_at'] >= $period['end']) { continue; }
            $out[] = array('title' => (string) $t['title'], 'notes' => (string) $t['notes'], 'minutes' => (int) $t['minutes'], 'closed_at' => (int) $t['closed_at']);
        }
        usort($out, function($a, $b) { return $a['closed_at'] <=> $b['closed_at']; });
        return $out;
    }

    /** Responsabili nel linguaggio del cliente: l'agenzia si chiama con il suo nome. */
    private static function owner_labels() {
        $b = Integra_Sentinel_Settings::get('branding');
        return array('agenzia' => (string) $b['name'], 'cliente' => 'Cliente', 'hosting' => 'Hosting', 'fornitore' => 'Produttore del plugin o del tema');
    }

    /**
     * Stato del backup per il report: controllo dell'ambiente, backup registrati a mano nel mese,
     * eventuale prova di ripristino riuscita. Ritorna value, tone, hint, rows, restore (timestamp o 0).
     */
    public static function backup_state($site, $period, $a) {
        $out = array('value' => 'Non verificato', 'tone' => 'warn', 'hint' => 'Dati del server non disponibili.', 'rows' => array(), 'restore' => 0);
        if ($a) {
            foreach ($a['environment']['checks'] as $c) {
                if ($c['key'] === 'backup') { $out['value'] = $c['value']; $out['tone'] = $c['tone']; $out['hint'] = $c['hint']; }
            }
        }
        $out['rows'] = array_reverse(Integra_Sentinel_Store::backups((string) $site['id'], $period['start'], $period['end'], 100));
        foreach ($out['rows'] as $row) { if ($row['method'] === 'restore' && $row['ok']) { $out['restore'] = max($out['restore'], $row['at']); } }
        return $out;
    }

    /** Il lavoro di sorveglianza del periodo, in frasi brevi e solo con numeri registrati. */
    public static function watch($site, $period, $a, $backup) {
        $id = (string) $site['id'];
        $r = (array) ($site['report'] ?? array());
        $lines = array();
        $pages = Integra_Sentinel_Probe::pages($site);
        foreach (Integra_Sentinel_Store::probe_counts($id, $period['start'], $period['end']) as $idx => $c) {
            if (!$c['checks']) { continue; }
            $label = $idx === 0 ? 'della home' : 'della pagina “' . (string) ($pages[$idx]['label'] ?? ('n. ' . $idx)) . '”';
            $lines[] = number_format_i18n($c['checks']) . ' controlli di raggiungibilità ' . $label . ', con risposta corretta nel ' . number_format_i18n(100 * $c['ok'] / $c['checks'], 2) . '% dei casi.';
        }
        $to = min($period['end'], time());
        $total = (int) ceil(($to - $period['start']) / DAY_IN_SECONDS);
        $analyzed = Integra_Sentinel_Store::metric_days($id, wp_date('Y-m-d', $period['start']), wp_date('Y-m-d', $period['end']));
        if ($analyzed > 0 && $total > 0) { $lines[] = 'Analisi completa di aggiornamenti, dipendenze e server in ' . min($analyzed, $total) . ' giorni su ' . $total . '.'; }
        $plugins = count((array) ($r['plugins'] ?? array()));
        $themes = count((array) ($r['themes'] ?? array()));
        if ($r) {
            $what = array('WordPress');
            if ($plugins) { $what[] = $plugins . ' plugin'; }
            if ($themes) { $what[] = $themes . ($themes === 1 ? ' tema' : ' temi'); }
            $lines[] = ($plugins + $themes + 1) . ' componenti (' . implode(', ', $what) . ') confrontati con i database pubblici delle vulnerabilità.';
        }
        if ($a && $a['core']['state'] === 'ok') { $lines[] = 'All’ultimo controllo ' . (int) $a['core']['checked'] . ' file di WordPress sono risultati identici agli originali pubblicati da WordPress.org.'; }
        if (isset($site['ssl_days_left']) && is_int($site['ssl_days_left']) && $site['ssl_days_left'] >= 0) { $lines[] = 'Certificato HTTPS controllato: valido per altri ' . $site['ssl_days_left'] . ' giorni.'; }
        $n = count($backup['rows']);
        if ($n) { $lines[] = ($n === 1 ? '1 backup verificato e registrato' : $n . ' backup verificati e registrati') . ' nel mese.'; }
        return $lines;
    }

    /* ---------- Punti aperti: chi deve intervenire e da quando ---------- */

    const OPEN_SINCE = 'integra_sentinel_open_since';

    /**
     * Lavoro giornaliero: annota il giorno in cui ogni collo di bottiglia compare nei dati di un sito.
     * Per i punti gia' aperti la prima volta che si vede il sito la data e' solo un limite ("almeno dal").
     */
    public static function track_open($fleet) {
        $now = time();
        return Integra_Sentinel_Master::mutate_option(self::OPEN_SINCE, function($map) use ($fleet, $now) {
            $out = array();
            foreach ($fleet['per_site'] as $id => $a) {
                $known = isset($map[$id]);
                $out[$id] = array();
                foreach (array_merge($a['bottlenecks'], $a['waived']) as $b) {
                    $key = $b['kind'] . '|' . $b['subject'];
                    $out[$id][$key] = $map[$id][$key] ?? array('at' => $now, 'base' => !$known);
                }
            }
            return $out;
        });
    }

    private static function since_text($p) {
        if (empty($p['since'])) { return '—'; }
        return ($p['base'] ? 'almeno dal ' : '') . wp_date('d/m/Y', (int) $p['since']);
    }

    /**
     * Punti aperti da mostrare al cliente: tutto cio' che e' a carico di cliente, hosting o produttore,
     * cio' che trattiene correzioni di sicurezza (anche se a carico nostro), le scelte concordate e gli interventi
     * in attesa di qualcuno. Ogni voce: title, detail, owner, since, base, security, agreed, until.
     */
    public static function open_points($site, $a) {
        $id = (string) $site['id'];
        $map = (array) (get_option(self::OPEN_SINCE, array())[$id] ?? array());
        $out = array(); $seen = array();
        $point = function($b, $agreed, $until) use ($map, &$seen) {
            $key = $b['kind'] . '|' . $b['subject'];
            $seen[$key] = true;
            return array('title' => (string) $b['title'], 'detail' => (string) $b['detail'], 'owner' => (string) $b['owner'],
                'since' => (int) ($map[$key]['at'] ?? 0), 'base' => !empty($map[$key]['base']), 'security' => !empty($b['security']), 'agreed' => $agreed, 'until' => $until);
        };
        if ($a) {
            foreach ($a['bottlenecks'] as $b) {
                if ($b['owner'] === 'agenzia' && empty($b['security'])) { continue; }
                $out[] = $point($b, '', '');
            }
            foreach ($a['waived'] as $b) { $out[] = $point($b, (string) ($b['waiver']['reason'] ?? '') ?: 'decisione registrata dall’agenzia', (string) ($b['waiver']['until'] ?? '')); }
        }
        $waiting = array('client' => 'cliente', 'hosting' => 'hosting', 'vendor' => 'fornitore');
        foreach (Integra_Sentinel_Store::tasks(array('site_id' => $id, 'status' => 'active', 'limit' => 100)) as $t) {
            if (!isset($waiting[$t['status']]) || ($t['kind'] !== '' && isset($seen[$t['kind'] . '|' . $t['subject']]))) { continue; }
            $out[] = array('title' => (string) $t['title'], 'detail' => '', 'owner' => $waiting[$t['status']], 'since' => (int) $t['created_at'], 'base' => false, 'security' => false, 'agreed' => '', 'until' => '');
        }
        $rank = array('cliente' => 0, 'hosting' => 1, 'fornitore' => 2, 'agenzia' => 3);
        usort($out, function($x, $y) use ($rank) {
            return (($rank[$x['owner']] ?? 4) <=> ($rank[$y['owner']] ?? 4)) ?: ((int) $y['security'] <=> (int) $x['security'])
                ?: (($x['since'] ?: PHP_INT_MAX) <=> ($y['since'] ?: PHP_INT_MAX));
        });
        return $out;
    }

    /**
     * Scadenze che riguardano il sito: certificato HTTPS vicino alla scadenza, licenze pagate dal cliente,
     * fine del supporto di PHP e del database. Ogni voce: what, at, days, owner.
     */
    public static function deadlines($site, $a) {
        $out = array();
        $r = (array) ($site['report'] ?? array());
        // I certificati gratuiti si rinnovano da soli a 30 giorni: si segnala solo quando il rinnovo e' in ritardo.
        if (isset($site['ssl_days_left']) && is_int($site['ssl_days_left']) && $site['ssl_days_left'] <= 21) {
            $out[] = array('what' => 'Certificato HTTPS del sito', 'at' => time() + $site['ssl_days_left'] * DAY_IN_SECONDS, 'days' => $site['ssl_days_left'], 'owner' => 'hosting');
        }
        $slugs = array();
        foreach ((array) ($r['plugins'] ?? array()) as $p) { $slugs[(string) ($p['slug'] ?? '')] = true; }
        foreach ((array) ($r['themes'] ?? array()) as $t) { if (!empty($t['role']) || !empty($t['active'])) { $slugs[(string) ($t['slug'] ?? '')] = true; } }
        foreach (Integra_Sentinel_Licenses::all() as $lic) {
            if (($lic['payer'] ?? '') !== 'cliente' || empty($lic['expires']) || !array_intersect(array_keys($slugs), (array) $lic['slugs'])) { continue; }
            if (!empty($lic['sites']) && !in_array((string) $site['id'], (array) $lic['sites'], true)) { continue; }
            $days = Integra_Sentinel_Licenses::days_left($lic);
            if ($days === null || $days > 60 || $days < -90) { continue; }
            $out[] = array('what' => 'Licenza ' . $lic['product'], 'at' => strtotime($lic['expires'] . ' 12:00:00'), 'days' => $days, 'owner' => 'cliente');
        }
        $platforms = array(array('php', (string) ($r['php'] ?? '')));
        $db = (array) ($r['env']['db'] ?? array());
        if (!empty($db['version'])) { $platforms[] = array(($db['type'] ?? '') === 'mariadb' ? 'mariadb' : 'mysql', (string) $db['version']); }
        foreach ($platforms as $pl) {
            if ($pl[1] === '') { continue; }
            $e = Integra_Sentinel_EOL::evaluate($pl[0], $pl[1]);
            if (!in_array($e['state'], array('soon', 'eol'), true)) { continue; }
            $out[] = array('what' => 'Fine del supporto di sicurezza di ' . Integra_Sentinel_EOL::label($pl[0]) . ' ' . $e['branch'] . ' (versione usata dal server)', 'at' => strtotime($e['eol'] . ' 12:00:00 UTC'), 'days' => $e['days'], 'owner' => 'hosting');
        }
        usort($out, function($x, $y) { return $x['days'] <=> $y['days']; });
        return $out;
    }

    /** Giudizio d'insieme in una riga, ricavato solo dai dati: tone (ok | warn | bad), title, text. */
    public static function verdict($m, $integrity, $points, $backup_tone) {
        $serious = 0;
        foreach ((array) $m['groups'] as $g) { if (in_array($g['max_tier'], array('critical', 'high'), true)) { ++$serious; } }
        $by_owner = array();
        foreach ($points as $p) { if ($p['agreed'] === '' && $p['owner'] !== 'agenzia') { $by_owner[$p['owner']] = ($by_owner[$p['owner']] ?? 0) + 1; } }
        $who = array();
        foreach (array('cliente' => 'del cliente', 'hosting' => 'dell’hosting', 'fornitore' => 'del produttore di un plugin o tema') as $owner => $label) {
            if (!empty($by_owner[$owner])) { $who[] = $by_owner[$owner] . ' a carico ' . $label; }
        }
        $who_text = $who ? ' Punti aperti: ' . implode(', ', $who) . ' (elencati qui sotto con la data di apertura).' : '';
        if ($integrity || $serious) {
            $risks = array();
            if ($serious) { $risks[] = $serious === 1 ? '1 componente con vulnerabilità note di gravità alta o critica' : $serious . ' componenti con vulnerabilità note di gravità alta o critica'; }
            if ($integrity) { $risks[] = $integrity === 1 ? '1 anomalia di integrità in verifica' : $integrity . ' anomalie di integrità in verifica'; }
            return array('tone' => 'bad', 'title' => 'Ci sono rischi aperti', 'text' => 'Oggi risultano ' . implode(' e ', $risks) . '.' . $who_text);
        }
        if ($m['affected'] || $who || in_array($backup_tone, array('warn', 'danger'), true)) {
            return array('tone' => 'warn', 'title' => 'Sito sorvegliato, con alcuni punti aperti', 'text' => ($m['affected'] ? 'Restano vulnerabilità note di gravità media o bassa.' : 'Nessuna vulnerabilità nota di gravità alta o critica.')
                . (in_array($backup_tone, array('warn', 'danger'), true) ? ' Il backup va verificato.' : '') . $who_text);
        }
        return array('tone' => 'ok', 'title' => 'Sito aggiornato e sorvegliato', 'text' => 'Nessuna vulnerabilità nota sulle versioni installate e nessuna anomalia di integrità; il backup risulta attivo.');
    }

    /**
     * Fatti del mese per il resoconto scritto con l'IA: solo dati verificabili, senza email, amministratori o percorsi.
     * Ogni motivazione viene dai dati (vulnerabilita' corrette, blocchi rilevati, eccezioni approvate, note dell'agenzia).
     */
    public static function facts($site, $ym, $notes = '') {
        $period = self::month_bounds($ym);
        $id = (string) $site['id'];
        $r = (array) ($site['report'] ?? array());
        $a = Integra_Sentinel_Analysis::site($site);
        $cache = (array) get_option(Integra_Sentinel_Master::FEEDS, array());
        $m = Integra_Sentinel_Master::sn_metrics($site, $cache);
        $severity = function($tier) { return strtolower(Integra_Sentinel_Master::sn_severity($tier)); };

        $added = array(); $removed = array(); $changelog_for = array(); $done = array();
        foreach (array_reverse(Integra_Sentinel_Store::events_between($period['start'], $period['end'], $id, array('install', 'remove'), 500)) as $e) {
            $row = array('data' => wp_date('d/m/Y', (int) $e['at']), 'componente' => trim((string) preg_replace('/^(Installato|Rimosso)\s+/u', '', (string) $e['message'])),
                'motivo_scritto_dall_agenzia' => trim((string) ($e['note'] ?? '')) !== '' ? trim((string) $e['note']) : null);
            if ($e['type'] === 'install') { $added[] = $row; } else { $removed[] = $row; }
        }
        $updates = self::month_updates($site, $period);
        foreach ($updates as $u) {
            if ($u['name'] === '') { continue; }
            $e = $u['event'];
            $note = trim((string) ($e['note'] ?? ''));
            $item = array('data' => wp_date('d/m/Y', (int) $e['at']), 'componente' => $u['name'], 'tipo' => array('core' => 'WordPress', 'plugin' => 'plugin', 'theme' => 'tema', '' => 'componente')[$u['type']],
                'da' => $u['from'], 'a' => $u['to'], 'salto' => self::step($u['from'], $u['to']),
                'aggiornato_automaticamente_da_wordpress' => ($e['flags'] ?? '') === 'auto' ? true : null, 'motivo_scritto_dall_agenzia' => $note !== '' ? $note : null);
            if ($u['fixed']) {
                // Quando Sentinel ha visto la falla rispetto alla data dell'aggiornamento (0 = data sconosciuta).
                $before = 0; $after = 0;
                foreach ($u['fixed'] as $f) {
                    if ($f['first_seen'] > 0 && $f['first_seen'] <= (int) $e['at']) { ++$before; }
                    elseif ($f['first_seen'] > (int) $e['at']) { ++$after; }
                }
                $item['vulnerabilita_corrette'] = array('numero' => count($u['fixed']), 'gravita_massima' => $severity(self::top_tier(array_column($u['fixed'], 'tier'))),
                    'gia_segnalate_prima_dell_aggiornamento' => $before, 'emerse_dopo_l_aggiornamento' => $after, 'con_data_di_segnalazione_sconosciuta' => count($u['fixed']) - $before - $after);
            }
            if ($u['type'] === 'plugin') { $changelog_for[count($done)] = array($u['slug'], $u['from'], $u['to']); }
            $done[] = $item;
        }
        // Changelog ufficiali (solo plugin della libreria WordPress.org): poche voci delle versioni installate nel mese.
        if ($changelog_for) {
            $wanted = array();
            foreach ($changelog_for as $c) { $wanted[$c[0]] = $c[2]; }
            $logs = Integra_Sentinel_Intel::changelogs($wanted);
            foreach ($changelog_for as $i => $c) {
                $items = array();
                foreach ((array) ($logs[$c[0]] ?? array()) as $version => $lines) {
                    if (version_compare($version, $c[1], '>') && version_compare($version, $c[2], '<=')) { $items = array_merge($items, $lines); }
                }
                if ($items) { $done[$i]['changelog_del_produttore'] = array_slice($items, 0, 8); }
            }
        }

        // Aggiornamenti disponibili ma non applicati, con il motivo solo se rilevato dai dati.
        $pending = array();
        $reasons_for = function($subject) use ($a) {
            $out = array();
            if (!$a) { return $out; }
            foreach ($a['bottlenecks'] as $b) {
                if ((string) ($b['node'] ?? '') === $subject || (string) $b['subject'] === $subject) { $out[] = $b['title'] . ': ' . $b['detail']; }
            }
            foreach ($a['waived'] as $b) {
                if ((string) ($b['node'] ?? '') === $subject || (string) $b['subject'] === $subject) { $out[] = 'Scelta concordata: ' . (string) ($b['waiver']['reason'] ?? ''); }
            }
            return array_values(array_unique($out));
        };
        if ($a) {
            foreach ($a['graph']['nodes'] as $n) {
                if (($n['new'] ?? '') === '' || in_array($n['role'], array('env', 'missing'), true)) { continue; }
                $why = $reasons_for((string) $n['id']);
                if (!$why && !in_array($n['status'], array('ok', 'update'), true)) { $why = array_slice((array) $n['reasons'], 0, 2); }
                $pending[] = array('componente' => $n['name'], 'tipo' => 'plugin', 'installata' => $n['ver'] ?? '', 'disponibile' => $n['new'], 'motivo' => $why ? implode(' ', $why) : null);
            }
            foreach ($a['themes']['themes'] as $t) {
                if ($t['update'] === '' || $t['role'] === '') { continue; }
                $why = $reasons_for((string) $t['slug']);
                if (!$why && $t['status'] !== 'update') { $why = array_slice((array) $t['reasons'], 0, 2); }
                $pending[] = array('componente' => $t['name'], 'tipo' => 'tema', 'installata' => $t['version'], 'disponibile' => $t['update'], 'motivo' => $why ? implode(' ', $why) : null);
            }
        }
        if (!empty($r['core_update'])) { $pending[] = array('componente' => 'WordPress', 'tipo' => 'WordPress', 'installata' => (string) $r['wp'], 'disponibile' => (string) $r['core_update'], 'motivo' => null); }

        $vulnerable = array();
        foreach ($m['groups'] as $g) {
            $fixed_in = '';
            foreach ((array) $g['items'] as $f) { if (!empty($f['fixed']) && ($fixed_in === '' || version_compare((string) $f['fixed'], $fixed_in, '>'))) { $fixed_in = (string) $f['fixed']; } }
            $vulnerable[] = array('componente' => $g['name'], 'segnalazioni' => (int) $g['count'], 'gravita_massima' => $severity($g['max_tier']), 'corretta_dalla_versione' => $fixed_in ?: null);
        }

        // Azioni a carico di altri, con la data da cui sono aperte: servono a mettere per iscritto le responsabilita'.
        $map = (array) (get_option(self::OPEN_SINCE, array())[$id] ?? array());
        $client_actions = array(); $hosting_actions = array(); $vendor_points = array(); $agreed = array();
        $since = function($b) use ($map) {
            $s = $map[$b['kind'] . '|' . $b['subject']] ?? null;
            return $s ? (!empty($s['base']) ? 'almeno dal ' : '') . wp_date('d/m/Y', (int) $s['at']) : null;
        };
        foreach ($a ? $a['bottlenecks'] : array() as $b) {
            $row = array('punto' => $b['title'], 'azione' => $b['action'], 'in_sospeso_dal' => $since($b), 'trattiene_correzioni_di_sicurezza' => !empty($b['security']));
            if ($b['owner'] === 'cliente') { $client_actions[] = $row; }
            elseif ($b['owner'] === 'hosting') { $hosting_actions[] = $row; }
            elseif ($b['owner'] === 'fornitore') { unset($row['azione']); $vendor_points[] = $row; }
        }
        foreach ($a ? $a['waived'] : array() as $b) {
            $agreed[] = array('punto' => $b['title'], 'motivo' => (string) ($b['waiver']['reason'] ?? '') ?: null, 'valida_fino_al' => !empty($b['waiver']['until']) ? wp_date('d/m/Y', strtotime($b['waiver']['until'] . ' 12:00:00')) : null);
        }
        foreach (Integra_Sentinel_Store::tasks(array('site_id' => $id, 'status' => 'active', 'limit' => 100)) as $t) {
            $row = array('punto' => $t['title'], 'in_attesa_dal' => wp_date('d/m/Y', (int) $t['created_at']));
            if ($t['status'] === 'client') { $client_actions[] = $row; }
            elseif ($t['status'] === 'hosting') { $hosting_actions[] = $row; }
        }

        $tasks = array();
        foreach (self::tasks_done($id, $period) as $t) {
            $tasks[] = array('intervento' => $t['title'], 'chiuso_il' => wp_date('d/m/Y', $t['closed_at']), 'nota' => $t['notes'] !== '' ? $t['notes'] : null, 'minuti_registrati' => $t['minutes'] ?: null);
        }

        $uptime = Integra_Sentinel_Store::uptime($id, $period['start'], $period['end']);
        $incidents = array();
        foreach (self::incidents($id, $period['start'], $period['end']) as $i) { $incidents[] = array('inizio' => wp_date('d/m/Y H:i', $i['from']), 'durata' => self::duration($i['to'] - $i['from'])); }
        $backup = self::backup_state($site, $period, $a);
        $backups = array();
        foreach ($backup['rows'] as $row) { $backups[] = array('data' => wp_date('d/m/Y', $row['at']), 'tipo' => $row['label'], 'riuscito' => $row['ok'], 'dettaglio' => $row['detail'] !== '' ? $row['detail'] : null); }
        $meta = (array) ($site['meta'] ?? array());
        $closed = self::closed_summary($updates);
        $deadlines = array();
        $owners = self::owner_labels();
        foreach (self::deadlines($site, $a) as $d) {
            $deadlines[] = array('cosa' => $d['what'], 'data' => wp_date('d/m/Y', $d['at']), 'giorni_mancanti' => $d['days'], 'gia_scaduta' => $d['days'] < 0, 'a_carico_di' => $owners[$d['owner']] ?? null);
        }

        $facts = array(
            'sito' => (string) $site['label'],
            'periodo' => ucfirst($period['label']),
            'aggiornamenti_applicati' => $done,
            'componenti_aggiunti' => $added,
            'componenti_rimossi' => $removed,
            'interventi_completati' => $tasks,
            'falle_di_sicurezza_chiuse_nel_mese' => $closed['count'] ? array('numero' => $closed['count'], 'gravita_massima' => $severity($closed['top']),
                'giorni_medi_tra_rilevazione_e_aggiornamento' => $closed['avg_days'], 'falle_con_data_di_rilevazione_nota' => $closed['timed']) : null,
            'sorveglianza_svolta' => self::watch($site, $period, $a, $backup),
            'aggiornamenti_non_ancora_applicati' => $pending,
            'vulnerabilita_note_ancora_presenti' => $vulnerable,
            'note_fisse_sui_componenti' => self::site_component_notes($site),
            'azioni_richieste_al_cliente' => $client_actions,
            'azioni_richieste_all_hosting' => $hosting_actions,
            'punti_in_attesa_del_produttore' => $vendor_points,
            'scelte_concordate' => $agreed,
            'scadenze' => $deadlines,
            'backup' => array('stato' => trim($backup['value'] . '. ' . $backup['hint'], '. '), 'backup_dell_hosting' => !empty($meta['hosting_backup']) ? (trim((string) ($meta['backup_desc'] ?? '')) ?: true) : null,
                'registrati_nel_mese' => $backups, 'prova_di_ripristino_riuscita_il' => $backup['restore'] ? wp_date('d/m/Y', $backup['restore']) : null),
            'disponibilita' => $uptime ? array('percentuale' => round((float) $uptime['pct'], 2), 'controlli' => (int) $uptime['checks'], 'tempo_medio_risposta_ms' => (int) $uptime['ms'], 'interruzioni' => $incidents) : null,
            'stato_tecnico' => array(
                'wordpress' => (string) ($r['wp'] ?? ''),
                'php' => !empty($r['php']) ? Integra_Sentinel_EOL::sentence('php', (string) $r['php']) : null,
                'certificato_https_giorni_alla_scadenza' => isset($site['ssl_days_left']) && is_int($site['ssl_days_left']) ? $site['ssl_days_left'] : null,
                'anomalie_di_integrita_aperte' => count((array) ($site['integrity_findings'] ?? array())),
            ),
            'note_dell_agenzia' => trim((string) $notes) !== '' ? trim((string) $notes) : null,
        );
        // Difesa in profondita': nessuna email lascia il Master, nemmeno se finita per errore nelle note.
        return json_decode(Integra_Sentinel_Master::mask_emails(wp_json_encode($facts, JSON_UNESCAPED_UNICODE)), true);
    }

    /** "3.24.7" → "3.25.4" = aggiornamento minore; serve al modello per non esagerare la portata. */
    private static function step($from, $to) {
        $a = explode('.', ltrim((string) $from, 'vV')); $b = explode('.', ltrim((string) $to, 'vV'));
        if (!is_numeric($a[0] ?? '') || !is_numeric($b[0] ?? '')) { return null; }
        if ((int) $a[0] !== (int) $b[0]) { return 'versione principale'; }
        if ((int) ($a[1] ?? 0) !== (int) ($b[1] ?? 0)) { return 'versione minore'; }
        return 'correzione';
    }

    /** Bozza del resoconto con l'IA, salvata come "da rileggere": non entra nel report finche' non viene approvata. */
    public static function ai_draft($site, $ym, $notes) {
        $period = self::month_bounds($ym);
        $facts = self::facts($site, $period['ym'], $notes);
        $result = Integra_Sentinel_AI::generate(Integra_Sentinel_AI::report_system_prompt(), Integra_Sentinel_AI::report_user_prompt($facts));
        if (!$result['ok']) {
            Integra_Sentinel_Store::draft_save($site['id'], $period['ym'], array('notes' => $notes));
            return $result;
        }
        Integra_Sentinel_Store::draft_save($site['id'], $period['ym'], array('body' => $result['text'], 'notes' => $notes, 'status' => 'draft', 'model' => $result['model']));
        return $result;
    }

    /* ---------- Note fisse sui componenti: spiegazioni dell'agenzia che valgono in tutti i report ---------- */

    const NOTES = 'integra_sentinel_component_notes';

    /** "sito|tipo|slug" => nota; sito "*" = valida per tutti i siti. */
    public static function component_notes() {
        $notes = get_option(self::NOTES, array());
        return is_array($notes) ? $notes : array();
    }

    /** Nota per un componente di un sito: prima quella del sito, poi quella valida per tutti. Ritorna text, fleet. */
    public static function component_note($site_id, $type, $slug) {
        $notes = self::component_notes();
        if (isset($notes[$site_id . '|' . $type . '|' . $slug])) { return array('text' => $notes[$site_id . '|' . $type . '|' . $slug], 'fleet' => false); }
        if (isset($notes['*|' . $type . '|' . $slug])) { return array('text' => $notes['*|' . $type . '|' . $slug], 'fleet' => true); }
        return array('text' => '', 'fleet' => false);
    }

    /**
     * Salva le note di un sito. $rows: "tipo:slug" => array(text, fleet).
     * Con "per tutti i siti" la nota diventa comune e quella del sito sparisce; senza, si salva sul sito solo se diversa da quella comune.
     */
    public static function save_component_notes($site_id, $rows) {
        return Integra_Sentinel_Master::mutate_option(self::NOTES, function($notes) use ($site_id, $rows) {
            foreach ((array) $rows as $key => $row) {
                if (!preg_match('/^(plugin|theme):([a-z0-9_-]{1,120})$/', (string) $key, $m)) { continue; }
                $text = substr(sanitize_textarea_field((string) ($row['text'] ?? '')), 0, 600);
                $mine = $site_id . '|' . $m[1] . '|' . $m[2];
                $all = '*|' . $m[1] . '|' . $m[2];
                if (!empty($row['fleet'])) {
                    unset($notes[$mine]);
                    if ($text === '') { unset($notes[$all]); } else { $notes[$all] = $text; }
                } elseif ($text === '' || $text === (string) ($notes[$all] ?? '')) {
                    unset($notes[$mine]);
                } else {
                    $notes[$mine] = $text;
                }
            }
            return array_slice($notes, -3000, null, true);
        });
    }

    /** Componenti installati con una nota: per i fatti dell'IA e per il report mensile. */
    public static function site_component_notes($site) {
        $out = array();
        $r = (array) ($site['report'] ?? array());
        foreach (array('plugin' => (array) ($r['plugins'] ?? array()), 'theme' => (array) ($r['themes'] ?? array())) as $type => $items) {
            foreach ($items as $c) {
                $note = self::component_note((string) $site['id'], $type, (string) ($c['slug'] ?? ''));
                if ($note['text'] !== '') { $out[] = array('componente' => (string) $c['name'], 'tipo' => $type === 'theme' ? 'tema' : 'plugin', 'versione_installata' => (string) ($c['version'] ?? ''), 'nota' => $note['text']); }
            }
        }
        return $out;
    }

    /* ---------- Bozze di tutti i siti: coda lavorata a piccoli lotti dal cron ---------- */

    const QUEUE = 'integra_sentinel_draft_queue';
    const AUTO_LAST = 'integra_sentinel_draft_auto_last';
    const HOOK_QUEUE = 'integra_sentinel_master_drafts';

    public static function queue() {
        $q = get_option(self::QUEUE, array());
        return is_array($q) && !empty($q['ym']) ? $q : array();
    }

    /**
     * Mette in coda le bozze di un mese per i siti che hanno un inventario e non hanno gia' un resoconto scritto
     * (le bozze esistenti non vengono mai sovrascritte). Ritorna il numero di siti in coda.
     */
    public static function enqueue_drafts($ym, $by = 'manual') {
        if (!Integra_Sentinel_AI::configured() || self::queue()) { return 0; }
        $period = self::month_bounds($ym);
        $existing = Integra_Sentinel_Store::drafts_for_month($period['ym']);
        $ids = array();
        foreach (Integra_Sentinel_Master::sites() as $id => $site) {
            if (empty($site['report']) || trim((string) ($existing[$id]['body'] ?? '')) !== '') { continue; }
            $ids[] = (string) $id;
        }
        if (!$ids) { return 0; }
        update_option(self::QUEUE, array('ym' => $period['ym'], 'sites' => $ids, 'total' => count($ids), 'done' => 0, 'failed' => array(), 'by' => $by, 'started' => time()), false);
        if (!wp_next_scheduled(self::HOOK_QUEUE)) { wp_schedule_single_event(time() + 5, self::HOOK_QUEUE); }
        Integra_Sentinel_Store::log('drafts_queue', count($ids) . ' bozze in coda · ' . $period['ym'] . ' · ' . $by);
        return count($ids);
    }

    /** Il primo giorno utile del mese prepara le bozze del mese precedente (impostazione dell'IA, attiva di serie). */
    public static function maybe_auto_drafts() {
        $cfg = Integra_Sentinel_Settings::get('ai');
        if (empty($cfg['auto_drafts']) || !Integra_Sentinel_AI::configured()) { return; }
        $prev = wp_date('Y-m', strtotime('first day of last month', current_time('timestamp')));
        if (get_option(self::AUTO_LAST) === $prev) { return; }
        update_option(self::AUTO_LAST, $prev, false);
        // Installazione o attivazione a mese inoltrato: niente lavoro a sorpresa, si riparte dal mese prossimo.
        if ((int) wp_date('j') > 7) { return; }
        self::enqueue_drafts($prev, 'auto');
    }

    /** Due bozze per giro; alla fine un solo avviso (email e canali) con il link alla pagina dei report. */
    public static function process_queue() {
        if (!Integra_Sentinel_Master::acquire_lock('drafts_queue', 0)) { return; }
        try {
            $q = self::queue();
            if (!$q) { return; }
            if ((int) ($q['wait_until'] ?? 0) > time()) {
                if (!wp_next_scheduled(self::HOOK_QUEUE)) { wp_schedule_single_event((int) $q['wait_until'], self::HOOK_QUEUE); }
                return;
            }
            $sites = Integra_Sentinel_Master::sites();
            for ($n = 0; $n < 2 && $q['sites']; ++$n) {
                $id = array_shift($q['sites']);
                $existing = Integra_Sentinel_Store::draft_get($id, $q['ym']);
                if (isset($sites[$id]) && trim((string) ($existing['body'] ?? '')) === '') {
                    $result = self::ai_draft($sites[$id], $q['ym'], (string) ($existing['notes'] ?? ''));
                    $retries = (int) ($q['retries'][$id] ?? 0);
                    if ($result['ok']) { ++$q['done']; unset($q['failed'][$id]); }
                    elseif (!empty($result['retryable']) && $retries < 3) {
                        // Fornitore sovraccarico: il sito torna in fondo alla coda e si riprova tra 10 minuti.
                        $q['sites'][] = $id;
                        $q['retries'][$id] = $retries + 1;
                        $q['wait_until'] = time() + 10 * MINUTE_IN_SECONDS;
                        update_option(self::QUEUE, $q, false);
                        break;
                    } else { $q['failed'][$id] = $result['error']; }
                }
                update_option(self::QUEUE, $q, false);
            }
            if ($q['sites']) {
                if (!wp_next_scheduled(self::HOOK_QUEUE)) { wp_schedule_single_event(max(time() + 30, (int) ($q['wait_until'] ?? 0)), self::HOOK_QUEUE); }
                return;
            }
            delete_option(self::QUEUE);
            self::queue_finished($q, $sites);
        } finally {
            Integra_Sentinel_Master::release_lock('drafts_queue');
        }
    }

    private static function queue_finished($q, $sites) {
        $period = self::month_bounds($q['ym']);
        $url = admin_url('admin.php?page=integra-sentinel-reports&ym=' . $period['ym']);
        $title = ($q['done'] === 1 ? '1 bozza del resoconto di ' . $period['label'] . ' pronta' : $q['done'] . ' bozze dei resoconti di ' . $period['label'] . ' pronte') . ' da rileggere';
        $text = 'Rileggile, correggile se serve e approvale prima dell’invio ai clienti.';
        if ($q['failed']) {
            $names = array();
            foreach (array_keys($q['failed']) as $id) { $names[] = (string) ($sites[$id]['label'] ?? $id); }
            $text .= ' Non riuscite (' . count($q['failed']) . '): ' . implode(', ', array_slice($names, 0, 10)) . '. Primo errore: ' . reset($q['failed']);
        }
        Integra_Sentinel_Store::log('drafts_queue', $title . ($q['failed'] ? ' · ' . count($q['failed']) . ' non riuscite' : ''));
        $body = Integra_Sentinel_Master::mail_notice('RESOCONTI DA RILEGGERE', $text, $q['failed'] ? 'warn' : 'brand') . Integra_Sentinel_Master::mail_cta('Apri i report clienti', $url);
        Integra_Sentinel_Master::send_html('[Integra Sentinel] ' . $title, Integra_Sentinel_Master::mail_shell($title, 'Resoconti per i clienti', $body, $q['failed'] ? 'warn' : 'brand'));
        Integra_Sentinel_Notify::send('drafts', $title, $text, $url);
    }

    /** Periodi di non raggiungibilita' della home ricavati dalle sonde. */
    public static function incidents($site_id, $from, $to) {
        $rows = array_reverse(Integra_Sentinel_Store::probes($site_id, $from, 5000));
        $out = array(); $open = null;
        foreach ($rows as $row) {
            if ((int) $row['at'] >= $to || (int) $row['url_idx'] !== 0) { continue; }
            if (!(int) $row['ok']) {
                if ($open === null) { $open = array('from' => (int) $row['at'], 'to' => (int) $row['at'], 'error' => (string) $row['error']); }
                else { $open['to'] = (int) $row['at']; }
            } elseif ($open !== null) {
                $open['to'] = (int) $row['at'];
                $out[] = $open; $open = null;
            }
        }
        if ($open !== null) { $out[] = $open; }
        return array_slice($out, 0, 40);
    }

    private static function duration($seconds) {
        $seconds = max(0, (int) $seconds);
        if ($seconds < 3600) { return max(1, (int) round($seconds / 60)) . ' min'; }
        $minutes = (int) round(($seconds % 3600) / 60);
        return floor($seconds / 3600) . ' h' . ($minutes ? ' ' . $minutes . ' min' : '');
    }

    /** Audit di presa in carico di un sito ereditato: rischi, colli di bottiglia e stima delle ore. */
    public static function onboarding_html($site) {
        $a = Integra_Sentinel_Analysis::site($site);
        $r = (array) ($site['report'] ?? array());
        $cache = (array) get_option(Integra_Sentinel_Master::FEEDS, array());
        $m = Integra_Sentinel_Master::sn_metrics($site, $cache);
        if (!$a) { return self::shell('Audit di presa in carico', $site['label'], '<h1>Audit di presa in carico</h1><p>In attesa del primo inventario del sito.</p>'); }
        $gs = $a['graph']['summary'];
        $active_plugins = 0;
        foreach ((array) ($r['plugins'] ?? array()) as $p) { if (!empty($p['active'])) { ++$active_plugins; } }
        $hours = $a['summary']['minutes'] / 60;
        $html = '<h1>Audit di presa in carico</h1><p class="sub">' . self::e($site['label']) . ' · ' . self::e($site['url']) . ' · ' . self::e(wp_date('d/m/Y')) . '</p>';
        $html .= '<div class="kpis"><div class="kpi"><b>' . count((array) ($r['plugins'] ?? array())) . '</b><span>plugin installati (' . $active_plugins . ' attivi)</span></div>'
            . '<div class="kpi"><b>' . (int) $gs['premium'] . '</b><span>fuori dalla libreria WordPress.org</span></div>'
            . '<div class="kpi"><b>' . (int) $a['summary']['bottlenecks'] . '</b><span>problemi che bloccano o complicano gli aggiornamenti</span></div>'
            . '<div class="kpi"><b>' . self::e(number_format_i18n($hours, 1)) . ' h</b><span>stima per mettere in regola il sito</span></div></div>';
        $html .= '<h2>Fotografia</h2><table><tbody>'
            . '<tr><td>WordPress</td><td>' . self::e($r['wp'] ?? '—') . (!empty($r['core_update']) ? ' (disponibile ' . self::e($r['core_update']) . ')' : '') . '</td></tr>'
            . '<tr><td>PHP</td><td>' . self::e(Integra_Sentinel_EOL::sentence('php', (string) ($r['php'] ?? ''))) . '</td></tr>'
            . '<tr><td>Vulnerabilità note</td><td>' . ($m['affected'] ? self::pill($m['affected'] . ' componenti, ' . $m['vulnerabilities'] . ' advisory', 'bad') : self::pill('Nessuna', 'ok')) . '</td></tr>'
            . '<tr><td>Integrità</td><td>' . (count((array) ($site['integrity_findings'] ?? array())) ? self::pill(count($site['integrity_findings']) . ' anomalie', 'bad') : self::pill('Nessuna anomalia', 'ok')) . '</td></tr>'
            . '<tr><td>Amministratori</td><td>' . count((array) ($r['security']['admins'] ?? array())) . '</td></tr>'
            . '<tr><td>Aggiornamenti disponibili</td><td>' . (int) $a['summary']['updates'] . ' (' . (int) $a['summary']['ready'] . ' applicabili subito, ' . (int) $a['summary']['blocked'] . ' bloccati)</td></tr>'
            . '</tbody></table>';
        $kinds = Integra_Sentinel_Fleet::kinds();
        $owners = Integra_Sentinel_Fleet::owners();
        $by_kind = array();
        foreach ($a['bottlenecks'] as $b) { $by_kind[$b['kind']][] = $b; }
        $html .= '<h2>Cosa va sistemato</h2>';
        if (!$by_kind) { $html .= '<p class="sub">Nessun collo di bottiglia rilevato nei dati disponibili.</p>'; }
        foreach ($kinds as $kind => $meta) {
            if (empty($by_kind[$kind])) { continue; }
            $html .= '<h3 style="font-size:14px;margin:16px 0 6px">' . self::e($meta['label']) . ' (' . count($by_kind[$kind]) . ')</h3><table><tbody>';
            foreach ($by_kind[$kind] as $b) {
                $tone = in_array($b['severity'], array('critical', 'high'), true) ? 'bad' : ($b['severity'] === 'medium' ? 'warn' : '');
                $html .= '<tr><td style="width:62%"><strong>' . self::e($b['title']) . '</strong><br>' . self::e($b['detail']) . '</td><td>' . self::pill(Integra_Sentinel_Fleet::severity_label($b['severity']), $tone) . '<br>' . self::e($owners[$b['owner']] ?? 'Agenzia') . ' · ~' . (int) $b['minutes'] . ' min</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '<div class="note">La stima comprende ' . (int) $a['summary']['ready'] . ' aggiornamenti applicabili subito e gli interventi elencati, secondo i tempi medi configurati dall’agenzia. Licenze da acquistare e lavori dell’hosting sono esclusi dalla stima.</div>';
        return self::shell('Audit di presa in carico', $site['label'], $html);
    }

    /**
     * Testi pronti da inviare: al cliente (licenze, decisioni) e all'hosting (PHP, permessi, spazio).
     * @return array client, hosting, subject
     */
    public static function requests($site) {
        $a = Integra_Sentinel_Analysis::site($site);
        $b = Integra_Sentinel_Settings::get('branding');
        $meta = (array) ($site['meta'] ?? array());
        $client = array(); $hosting = array();
        foreach ($a ? $a['bottlenecks'] : array() as $item) {
            if ($item['owner'] === 'cliente') {
                $cost = '';
                foreach (Integra_Sentinel_Licenses::for_slug((string) $item['subject']) as $lic) { if (!empty($lic['cost'])) { $cost = ' (circa ' . number_format_i18n((float) $lic['cost'], 2) . ' €/anno)'; break; } }
                $client[] = '- ' . $item['title'] . $cost . ': ' . $item['action'];
            } elseif ($item['owner'] === 'hosting') {
                $hosting[] = '- ' . $item['title'] . '. ' . $item['detail'];
            }
        }
        $greeting = !empty($meta['contact_name']) ? 'Gentile ' . $meta['contact_name'] . ',' : 'Buongiorno,';
        $client_text = $client ? $greeting . "\n\nper mantenere aggiornato e sicuro il sito " . $site['url'] . " servono alcune azioni da parte vostra:\n\n" . implode("\n", $client)
            . "\n\nSenza questi passaggi gli aggiornamenti dei componenti indicati restano bloccati. Restiamo a disposizione per qualsiasi chiarimento.\n\n" . $b['name'] : '';
        $hosting_text = $hosting ? "Buongiorno,\n\nper il sito " . $site['url'] . " chiediamo il vostro intervento su:\n\n" . implode("\n", $hosting)
            . "\n\nGrazie,\n" . $b['name'] : '';
        return array('client' => $client_text, 'hosting' => $hosting_text, 'subject' => 'Aggiornamenti del sito ' . wp_parse_url((string) $site['url'], PHP_URL_HOST) . ': azioni necessarie');
    }

    public static function send_monthly($site, $ym, $to) {
        $period = self::month_bounds($ym);
        $to = sanitize_email($to);
        if (!is_email($to)) { return false; }
        $b = Integra_Sentinel_Settings::get('branding');
        $headers = array('Content-Type: text/html; charset=UTF-8');
        if (!empty($b['from_name'])) { $headers[] = 'From: ' . sanitize_text_field($b['from_name']) . ' <' . get_option('admin_email') . '>'; }
        return (bool) wp_mail($to, 'Report di manutenzione ' . $site['label'] . ' · ' . ucfirst($period['label']), self::monthly_html($site, $period['ym']), $headers);
    }
}
