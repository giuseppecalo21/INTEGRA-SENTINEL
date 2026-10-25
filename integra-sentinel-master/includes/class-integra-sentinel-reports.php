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
            . '.note{background:#f6f9fb;border-left:3px solid var(--c);padding:10px 14px;margin:12px 0;color:#2e4356}ul{padding-left:20px}li{margin:3px 0}'
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

    /** Aggiornamenti e incidenti del mese, stato attuale e prossimi passi: il documento che giustifica il canone. */
    public static function monthly_html($site, $ym, $draft_preview = false) {
        $period = self::month_bounds($ym);
        $id = (string) $site['id'];
        $events = Integra_Sentinel_Store::events($id, $period['start'], $period['end'], 1000);
        $updates = array_values(array_filter($events, function($e) { return in_array($e['type'], array('update', 'core'), true); }));
        $installed = array_values(array_filter($events, function($e) { return in_array($e['type'], array('install', 'remove'), true); }));
        $uptime = Integra_Sentinel_Store::uptime($id, $period['start'], $period['end']);
        $incidents = self::incidents($id, $period['start'], $period['end']);
        $cache = (array) get_option(Integra_Sentinel_Master::FEEDS, array());
        $m = Integra_Sentinel_Master::sn_metrics($site, $cache);
        $a = Integra_Sentinel_Analysis::site($site);
        $r = (array) ($site['report'] ?? array());
        $integrity = count((array) ($site['integrity_findings'] ?? array()));

        $html = '<h1>Report di manutenzione</h1><p class="sub">' . self::e($site['label']) . ' · ' . self::e($site['url']) . ' · ' . self::e(ucfirst($period['label'])) . '</p>';
        // Resoconto scritto per il cliente: entra nel report solo dopo l'approvazione (l'anteprima mostra anche le bozze).
        $draft = Integra_Sentinel_Store::draft_get($id, $period['ym']);
        if ($draft && trim((string) $draft['body']) !== '' && ($draft_preview || in_array($draft['status'], array('approved', 'sent'), true))) {
            $b = Integra_Sentinel_Settings::get('branding');
            if ($draft['status'] === 'draft') { $html .= '<p class="note" style="border-left-color:#c58a12">Anteprima di una bozza non ancora approvata: il cliente non la vede finché non viene approvata.</p>'; }
            $html .= '<div class="story">' . Integra_Sentinel_AI::to_html($draft['body'], preg_match('/^#[0-9a-f]{6}$/i', (string) $b['color']) ? $b['color'] : '#0c8997') . '</div><h2>I numeri del mese</h2>';
        }
        $html .= '<div class="kpis"><div class="kpi"><b>' . count($updates) . '</b><span>' . (count($updates) === 1 ? 'aggiornamento applicato' : 'aggiornamenti applicati') . '</span></div>'
            . '<div class="kpi"><b>' . ($uptime ? self::e(number_format_i18n($uptime['pct'], 2)) . '%' : 'n.d.') . '</b><span>disponibilità del sito</span></div>'
            . '<div class="kpi"><b>' . (int) $m['affected'] . '</b><span>' . ((int) $m['affected'] === 1 ? 'componente con vulnerabilità note oggi' : 'componenti con vulnerabilità note oggi') . '</span></div>'
            . '<div class="kpi"><b>' . ($integrity ? $integrity : '0') . '</b><span>' . ($integrity === 1 ? 'anomalia di integrità oggi' : 'anomalie di integrità oggi') . '</span></div></div>';
        $parts = array();
        $parts[] = count($updates) ? (count($updates) === 1 ? 'Nel mese abbiamo applicato 1 aggiornamento.' : 'Nel mese abbiamo applicato ' . count($updates) . ' aggiornamenti tra WordPress, plugin e temi.') : 'Nel mese non sono stati registrati aggiornamenti di versione.';
        if ($uptime) { $parts[] = 'Il sito ha risposto correttamente nel ' . number_format_i18n($uptime['pct'], 2) . '% dei ' . (int) $uptime['checks'] . ' controlli (tempo medio ' . (int) $uptime['ms'] . ' ms).'; }
        $parts[] = $m['affected'] ? ((int) $m['affected'] === 1 ? 'Resta 1 componente con vulnerabilità note, indicato qui sotto.' : 'Restano ' . (int) $m['affected'] . ' componenti con vulnerabilità note, indicati qui sotto.') : 'Ad oggi non risultano vulnerabilità note applicabili alle versioni installate.';
        // Prerequisiti da sistemare: in evidenza subito dopo il riepilogo, perche' spiegano cosa non si puo' ancora fare.
        $checklist = Integra_Sentinel_Checklist::in_monthly($site) ? Integra_Sentinel_Checklist::summary($site) : null;
        $checklist_top = $checklist && $checklist['missing'];
        if ($checklist_top) { $parts[] = $checklist['missing'] === 1 ? 'Un prerequisito per la manutenzione è da sistemare: qui sotto spieghiamo cosa comporta.' : $checklist['missing'] . ' prerequisiti per la manutenzione sono da sistemare: qui sotto spieghiamo cosa comportano.'; }
        $html .= '<div class="note">' . self::e(implode(' ', $parts)) . '</div>';
        if ($checklist_top) { $html .= Integra_Sentinel_Checklist::document_html($site, 'Accessi, backup e accordi che verifichiamo prima di intervenire sul sito. Le voci da sistemare indicano cosa non è ancora possibile fare.'); }

        $html .= '<h2>Aggiornamenti applicati</h2>';
        if (!$updates) { $html .= '<p class="sub">Nessun cambio di versione registrato nel periodo.</p>'; }
        else {
            $html .= '<table><thead><tr><th>Data</th><th>Componente e versione</th></tr></thead><tbody>';
            foreach (array_reverse($updates) as $e) { $html .= '<tr><td>' . self::e(wp_date('d/m/Y', (int) $e['at'])) . '</td><td>' . self::e($e['message']) . (trim((string) ($e['note'] ?? '')) !== '' ? '<br><small style="color:#5c6e80">' . self::e($e['note']) . '</small>' : '') . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        if ($installed) {
            $html .= '<h2>Plugin e temi aggiunti o rimossi</h2><ul>';
            foreach (array_reverse($installed) as $e) { $html .= '<li>' . self::e(wp_date('d/m/Y', (int) $e['at']) . ' · ' . $e['message']) . '</li>'; }
            $html .= '</ul>';
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
        if ($a) {
            foreach ($a['environment']['checks'] as $c) {
                if ($c['key'] === 'backup') { $html .= '<tr><td>Backup</td><td>' . self::pill($c['value'], $c['tone'] === 'ok' ? 'ok' : 'warn') . ' ' . self::e($c['hint']) . '</td></tr>'; }
            }
        }
        $html .= '</tbody></table>';
        if ($checklist && !$checklist_top) {
            $html .= Integra_Sentinel_Checklist::document_html($site, 'Accessi, backup e accordi che verifichiamo prima di intervenire sul sito.');
        }
        if ($incidents) {
            $html .= '<h2>Interruzioni rilevate</h2><table><thead><tr><th>Inizio</th><th>Durata stimata</th><th>Dettaglio</th></tr></thead><tbody>';
            foreach ($incidents as $i) { $html .= '<tr><td>' . self::e(wp_date('d/m/Y H:i', $i['from'])) . '</td><td>' . self::e(self::duration($i['to'] - $i['from'])) . '</td><td>' . self::e($i['error']) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        return self::shell('Report di manutenzione', $site['label'] . ' · ' . ucfirst($period['label']), $html);
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

        // Nome visualizzato → slug, per ritrovare i feed dei componenti citati negli eventi.
        $by_name = array();
        foreach ((array) ($r['plugins'] ?? array()) as $p) { $by_name['plugin'][(string) $p['name']] = (string) $p['slug']; }
        foreach ((array) ($r['themes'] ?? array()) as $t) { $by_name['theme'][(string) $t['name']] = (string) $t['slug']; }

        $done = array(); $added = array(); $removed = array(); $changelog_for = array();
        foreach (array_reverse(Integra_Sentinel_Store::events($id, $period['start'], $period['end'], 1000)) as $e) {
            $date = wp_date('d/m/Y', (int) $e['at']);
            $note = trim((string) ($e['note'] ?? '')) !== '' ? trim((string) $e['note']) : null;
            if ($e['type'] === 'install') { $added[] = array('data' => $date, 'componente' => trim(substr($e['message'], strlen('Installato '))), 'motivo_scritto_dall_agenzia' => $note); continue; }
            if ($e['type'] === 'remove') { $removed[] = array('data' => $date, 'componente' => trim(substr($e['message'], strlen('Rimosso '))), 'motivo_scritto_dall_agenzia' => $note); continue; }
            if (!in_array($e['type'], array('update', 'core'), true) || !preg_match('/^(?:\[MU\] )?(.+): (\S*) → (\S*)$/u', (string) $e['message'], $mm)) { continue; }
            $name = $mm[1]; $from = $mm[2]; $to = $mm[3];
            $type = $e['type'] === 'core' ? 'core' : (isset($by_name['plugin'][$name]) ? 'plugin' : (isset($by_name['theme'][$name]) ? 'theme' : ''));
            $item = array('data' => $date, 'componente' => $name, 'tipo' => array('core' => 'WordPress', 'plugin' => 'plugin', 'theme' => 'tema', '' => 'componente')[$type],
                'da' => $from, 'a' => $to, 'salto' => self::step($from, $to),
                'aggiornato_automaticamente_da_wordpress' => ($e['flags'] ?? '') === 'auto' ? true : null, 'motivo_scritto_dall_agenzia' => $note);
            if ($type !== '') {
                $fixed = Integra_Sentinel_Master::advisories_fixed($type, $type === 'core' ? '' : $by_name[$type][$name], $from, $to);
                if ($fixed) {
                    $tiers = array_column($fixed, 'tier');
                    $top = 'unknown';
                    foreach (array('critical', 'high', 'medium', 'low') as $tier) { if (in_array($tier, $tiers, true)) { $top = $tier; break; } }
                    // Quando Sentinel ha visto la falla rispetto alla data dell'aggiornamento (0 = data sconosciuta).
                    $before = 0; $after = 0;
                    foreach ($fixed as $f) {
                        if ($f['first_seen'] > 0 && $f['first_seen'] <= (int) $e['at']) { ++$before; }
                        elseif ($f['first_seen'] > (int) $e['at']) { ++$after; }
                    }
                    $item['vulnerabilita_corrette'] = array('numero' => count($fixed), 'gravita_massima' => $severity($top),
                        'gia_segnalate_prima_dell_aggiornamento' => $before, 'emerse_dopo_l_aggiornamento' => $after, 'con_data_di_segnalazione_sconosciuta' => count($fixed) - $before - $after);
                }
                if ($type === 'plugin') { $changelog_for[count($done)] = array($by_name['plugin'][$name], $from, $to); }
            }
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

        $client_actions = array(); $hosting_actions = array();
        foreach ($a ? $a['bottlenecks'] : array() as $b) {
            if ($b['owner'] === 'cliente') { $client_actions[] = $b['title'] . ' — ' . $b['action']; }
            elseif ($b['owner'] === 'hosting') { $hosting_actions[] = $b['title'] . ' — ' . $b['action']; }
        }

        $tasks = array();
        foreach (Integra_Sentinel_Store::tasks(array('site_id' => $id, 'status' => 'done', 'limit' => 200)) as $t) {
            if ((int) $t['closed_at'] < $period['start'] || (int) $t['closed_at'] >= $period['end']) { continue; }
            $tasks[] = array('intervento' => $t['title'], 'nota' => $t['notes'] !== '' ? $t['notes'] : null);
        }

        $uptime = Integra_Sentinel_Store::uptime($id, $period['start'], $period['end']);
        $incidents = array();
        foreach (self::incidents($id, $period['start'], $period['end']) as $i) { $incidents[] = array('inizio' => wp_date('d/m/Y H:i', $i['from']), 'durata' => self::duration($i['to'] - $i['from'])); }
        $backup = null;
        if ($a) { foreach ($a['environment']['checks'] as $c) { if ($c['key'] === 'backup') { $backup = trim($c['value'] . '. ' . $c['hint'], '. '); } } }

        $facts = array(
            'sito' => (string) $site['label'],
            'periodo' => ucfirst($period['label']),
            'aggiornamenti_applicati' => $done,
            'componenti_aggiunti' => $added,
            'componenti_rimossi' => $removed,
            'interventi_completati' => $tasks,
            'aggiornamenti_non_ancora_applicati' => $pending,
            'vulnerabilita_note_ancora_presenti' => $vulnerable,
            'note_fisse_sui_componenti' => self::site_component_notes($site),
            'azioni_richieste_al_cliente' => $client_actions,
            'azioni_richieste_all_hosting' => $hosting_actions,
            'disponibilita' => $uptime ? array('percentuale' => round((float) $uptime['pct'], 2), 'controlli' => (int) $uptime['checks'], 'tempo_medio_risposta_ms' => (int) $uptime['ms'], 'interruzioni' => $incidents) : null,
            'stato_tecnico' => array(
                'wordpress' => (string) ($r['wp'] ?? ''),
                'php' => !empty($r['php']) ? Integra_Sentinel_EOL::sentence('php', (string) $r['php']) : null,
                'certificato_https_giorni_alla_scadenza' => isset($site['ssl_days_left']) && is_int($site['ssl_days_left']) ? $site['ssl_days_left'] : null,
                'backup' => $backup,
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
        $html .= Integra_Sentinel_Checklist::document_html($site, 'Accessi, backup e accordi necessari per aggiornare il sito in sicurezza, con lo stato verificato alla data di questo audit. Le voci da sistemare indicano cosa non è ancora possibile fare.');
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
