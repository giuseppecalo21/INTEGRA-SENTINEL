<?php
/**
 * Integra Sentinel — analisi dei temi: origine, licenza, child theme, personalizzazioni e plugin inclusi.
 *
 * Classe pura: lavora sull'inventario sanificato e restituisce righe per la UI e "colli di bottiglia"
 * nello stesso formato del motore delle dipendenze.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Themes {

    /** Temi commerciali molto diffusi venduti su ThemeForest (slug della cartella). */
    public static function envato_slugs() {
        return array('avada', 'enfold', 'dt-the7', 'the7', 'betheme', 'bridge', 'salient', 'flatsome', 'newspaper', 'jupiter', 'jupiterx',
            'porto', 'impreza', 'uncode', 'kalium', 'oshine', 'bimber', 'woodmart', 'thegem', 'total', 'x', 'houzez', 'consulting',
            'medicenter', 'soledad', 'pofo', 'movedo', 'ronneby', 'zeen', 'jannah', 'sahifa', 'goodnews', 'motors', 'pearl', 'konte', 'basel', 'kallyas');
    }

    /** Temi commerciali con updater proprio e chiave API (Elegant Themes). */
    public static function elegant_slugs() {
        return array('divi', 'extra');
    }

    private static function host($url) {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_match('/^[a-z0-9.-]{1,120}$/', $host) ? $host : '';
    }

    private static function issue($kind, $subject, $name, $severity, $owner, $title, $detail, $action, $score, $security = false) {
        return array('id' => 'theme:' . $kind . ':' . $subject, 'kind' => $kind, 'subject' => $subject, 'subjectName' => $name, 'severity' => $severity,
            'owner' => $owner, 'title' => $title, 'detail' => $detail, 'action' => $action, 'unlocks' => array(), 'security' => $security,
            'score' => $score, 'node' => '');
    }

    /**
     * @param array $ctx catalog (slug => version/url/source), fleet (versioni dei temi nel parco), verified, theme_hashes (slug|version => hash => count),
     *                   vulns (slug => count/tier), owners (slug => agenzia|cliente), wp, php
     */
    public static function analyze($report, $ctx = array()) {
        $report = is_array($report) ? $report : array();
        $catalog = (array) ($ctx['catalog'] ?? array());
        $fleet = (array) ($ctx['fleet'] ?? array());
        $verified = (array) ($ctx['verified'] ?? array());
        $hashes = (array) ($ctx['theme_hashes'] ?? array());
        $vulns = (array) ($ctx['vulns'] ?? array());
        $owners = (array) ($ctx['owners'] ?? array());
        $wp = (string) ($report['wp'] ?? '');
        $php = (string) ($report['php'] ?? '');
        $rows = array(); $issues = array(); $active = ''; $template = '';
        $inactive = array();

        $by_slug = array();
        foreach ((array) ($report['themes'] ?? array()) as $t) { if (is_array($t) && !empty($t['slug'])) { $by_slug[$t['slug']] = $t; } }
        foreach ($by_slug as $slug => $t) {
            $role = (string) ($t['role'] ?? '');
            if ($role === '' && !empty($t['active']) && !isset($t['role'])) { $role = 'active'; } // Client < 1.7
            if ($role === 'active') { $active = $slug; }
            if ($role === 'parent') { $template = $slug; }
        }
        if ($active !== '' && $template === '') { $template = (string) ($by_slug[$active]['parent'] ?? '') ?: $active; }

        foreach ($by_slug as $slug => $t) {
            $role = (string) ($t['role'] ?? (!empty($t['active']) ? 'active' : ''));
            $channel = is_array($t['channel'] ?? null) ? $t['channel'] : array();
            $type = (string) ($channel['type'] ?? '');
            $hosts = array(self::host($t['theme_uri'] ?? ''), self::host($t['author_uri'] ?? ''));
            $envato = in_array($slug, self::envato_slugs(), true);
            foreach ($hosts as $h) { if (preg_match('/(^|\.)(themeforest\.net|envato\.com|envato\.market)$/', $h)) { $envato = true; } }
            $elegant = in_array($slug, self::elegant_slugs(), true);
            $wporg_uri = in_array('wordpress.org', $hosts, true);
            $update = (string) ($t['update'] ?? '');
            $info = is_array($t['update_info'] ?? null) ? $t['update_info'] : array();
            $license = is_array($t['license'] ?? null) ? (string) ($t['license']['status'] ?? '') : '';

            $origin = 'unknown'; $label = 'Origine non rilevata'; $premium = false;
            if ($type === 'wporg') { $origin = 'wporg'; $label = 'Libreria WordPress.org'; }
            elseif ($type === 'vendor') { $origin = 'vendor'; $premium = true; $label = $envato ? 'Envato · aggiornamenti attivi' : ($elegant ? 'Elegant Themes · aggiornamenti attivi' : 'Premium · updater del fornitore'); }
            elseif ($type === 'none' || $type === 'unknown') {
                if ($envato) { $origin = 'nochannel'; $premium = true; $label = 'Envato / ThemeForest · aggiornamento manuale'; }
                elseif ($elegant) { $origin = 'nochannel'; $premium = true; $label = 'Elegant Themes · chiave API assente'; }
                elseif ($wporg_uri) { $origin = 'nochannel'; $label = 'Non più disponibile su WordPress.org'; }
                elseif ($hosts[0] !== '' || $hosts[1] !== '') { $origin = 'nochannel'; $premium = true; $label = 'Tema commerciale senza canale'; }
                else { $origin = 'custom'; $label = 'Su misura'; }
                if ($type === 'unknown' && $origin === 'custom') { $origin = 'unknown'; $label = 'Canale non ancora verificato da WordPress'; }
            } elseif ($envato || $elegant) { $origin = 'nochannel'; $premium = true; $label = 'Probabile tema commerciale (Client < 1.7)'; }

            $latest = ''; $latest_src = ''; $latest_url = ''; $latest_note = ''; $verified_note = '';
            if (!empty($catalog[$slug]['version']) && Integra_Sentinel_Dependencies::valid_version((string) $catalog[$slug]['version'])) {
                $latest = (string) $catalog[$slug]['version'];
                $latest_src = (string) ($catalog[$slug]['source'] ?? 'catalogo Integra');
                $latest_url = (string) ($catalog[$slug]['url'] ?? '');
            }
            $known = $latest !== '';
            if ($type !== 'wporg') {
                // Incrocio con gli altri siti: una versione piu' recente altrove vuol dire che un aggiornamento c'e'.
                $ref = Integra_Sentinel_Dependencies::fleet_reference($slug, (string) ($t['name'] ?? $slug), (string) ($t['version'] ?? ''), $fleet, $verified);
                if ($ref['version'] !== '' && ($latest === '' || Integra_Sentinel_Dependencies::newer($ref['version'], $latest))) {
                    $latest = $ref['version']; $latest_src = $ref['source']; $latest_note = $ref['note'];
                }
                $known = $known || $ref['known'];
                $verified_note = $ref['verified'];
            }
            $behind = $latest !== '' && Integra_Sentinel_Dependencies::valid_version((string) ($t['version'] ?? '')) && version_compare(ltrim($latest, 'v'), ltrim((string) $t['version'], 'v'), '>');
            $in_use = $role !== '';
            $reasons = array(); $status = 'ok';
            $vuln = $vulns[$slug] ?? null;
            // Come per i plugin: "agenzia" se la licenza e' dell'agenzia o non e' ancora registrata, "cliente" se registrata come del cliente.
            $registered = isset($owners[$slug]);
            $owner = $owners[$slug] ?? 'agenzia';

            if ($update !== '') {
                $need_php = (string) ($info['requires_php'] ?? '');
                $need_wp = (string) ($info['requires_wp'] ?? '');
                if (isset($info['pkg']) && (int) $info['pkg'] === 0) {
                    $status = 'license';
                    $reasons[] = 'Aggiornamento ' . $update . ' visibile ma non scaricabile: licenza o registrazione del tema mancante.';
                } elseif ($need_php !== '' && $php !== '' && version_compare($php, $need_php, '<')) {
                    $status = 'blocked'; $reasons[] = 'La versione ' . $update . ' richiede PHP ' . $need_php . ' (server ' . $php . ').';
                } elseif ($need_wp !== '' && $wp !== '' && version_compare($wp, $need_wp, '<')) {
                    $status = 'blocked'; $reasons[] = 'La versione ' . $update . ' richiede WordPress ' . $need_wp . '.';
                } else {
                    $status = 'update';
                }
            } elseif (in_array($license, array('missing', 'expired', 'invalid'), true)) {
                $status = 'license';
                $reasons[] = $license === 'missing' ? 'Chiave di licenza o API non inserita: il tema non riceve aggiornamenti.' : 'Licenza scaduta o non valida.';
            } elseif ($origin === 'nochannel' && $premium) {
                if ($behind) { $status = 'manual'; $reasons[] = $latest_note !== '' ? $latest_note . ' Va aggiornato a mano dal fornitore.' : 'Disponibile ' . $latest . ' (' . $latest_src . '): aggiornamento manuale dal fornitore.'; }
                elseif (!$known) { $status = 'blind'; $reasons[] = 'Nessun canale di aggiornamento e nessuna versione di riferimento nota.'; }
                elseif ($verified_note !== '') { $reasons[] = $verified_note; }
            }

            // Personalizzazioni nel tema: functions.php diverso dagli altri siti con la stessa versione.
            $customized = false;
            $hash = (string) ($t['hash'] ?? '');
            $hkey = $slug . '|' . (string) ($t['version'] ?? '');
            if ($hash !== '' && isset($hashes[$hkey]) && is_array($hashes[$hkey])) {
                $counts = $hashes[$hkey];
                $total = array_sum($counts);
                $mine = (int) ($counts[$hash] ?? 0);
                if ($total >= 3 && $mine === 1 && max($counts) >= 2) { $customized = true; $reasons[] = 'functions.php diverso dagli altri siti con la stessa versione: probabile personalizzazione nel tema.'; }
            }
            $no_child = $role === 'active' && empty($t['parent']) && $premium;

            $rows[] = array('slug' => $slug, 'name' => (string) ($t['name'] ?? $slug), 'version' => (string) ($t['version'] ?? ''), 'update' => $update,
                'role' => $role, 'parent' => (string) ($t['parent'] ?? ''), 'origin' => $origin, 'originLabel' => $label, 'premium' => $premium,
                'envato' => $envato, 'license' => $license, 'latest' => $latest, 'latestSrc' => $latest_src, 'latestUrl' => $latest_url,
                'status' => $status, 'reasons' => $reasons, 'customized' => $customized, 'noChild' => $no_child, 'vuln' => $vuln);

            if (!$in_use) {
                if (strpos($slug, 'twenty') !== 0) { $inactive[] = (string) ($t['name'] ?? $slug); }
                continue;
            }
            $name = (string) ($t['name'] ?? $slug);
            $has_vuln = is_array($vuln) && !empty($vuln['count']);
            if ($status === 'license') {
                $issues[] = self::issue('license', $slug, $name, $has_vuln ? 'critical' : ($update !== '' ? 'high' : 'medium'), $owner,
                    'Tema ' . $name . ': licenza da sistemare', implode(' ', $reasons),
                    !$registered ? 'Licenza non registrata: verificare chi la possiede, attivarla sul tema oppure farla rinnovare al cliente.'
                        : ($owner === 'agenzia' ? 'Registrare il tema con la licenza dell’agenzia e aggiornarlo.' : 'Chiedere al cliente di rinnovare o attivare la licenza del tema.'),
                    $has_vuln ? 60 : 24, $has_vuln);
            } elseif ($status === 'blocked') {
                $issues[] = self::issue('env', 'theme-' . $slug, $name, $has_vuln ? 'critical' : 'high', 'hosting', 'Tema ' . $name . ' non aggiornabile', implode(' ', $reasons),
                    'Aggiornare prima PHP o WordPress, poi il tema.', 22, $has_vuln);
            } elseif ($status === 'manual') {
                $issues[] = self::issue('manual', $slug, $name, $has_vuln ? 'high' : 'low', 'agenzia', 'Tema ' . $name . ': ' . $t['version'] . ' → ' . $latest . ' disponibile fuori da WordPress',
                    implode(' ', $reasons), 'Scaricare il pacchetto dal fornitore e aggiornare il tema (verificare prima child theme e personalizzazioni).', $has_vuln ? 36 : 8, $has_vuln);
            } elseif ($status === 'blind') {
                $issues[] = self::issue('blind', $slug, $name, $has_vuln ? 'high' : 'low', 'agenzia', 'Tema ' . $name . ': ultima versione sconosciuta',
                    $label . '. Nessun canale e nessuna versione di riferimento nel catalogo o negli acquisti Envato.',
                    'Verificare la versione sul sito del fornitore: se è già l’ultima, segnarlo con «Aggiornato all’ultima versione», altrimenti aggiornare.', $has_vuln ? 30 : 5, $has_vuln);
            }
            if ($customized) {
                $issues[] = self::issue('modified', 'theme-' . $slug, $name, $no_child ? 'high' : 'medium', 'agenzia', 'Tema ' . $name . ' personalizzato direttamente',
                    'functions.php è diverso dagli altri siti con la stessa versione' . ($no_child ? ' e non c’è un child theme: l’aggiornamento cancellerebbe le modifiche.' : '.'),
                    'Spostare le personalizzazioni in un child theme prima di aggiornare.', $no_child ? 26 : 12);
            } elseif ($no_child) {
                $issues[] = self::issue('theme', 'child-' . $slug, $name, 'low', 'agenzia', 'Tema ' . $name . ' senza child theme',
                    'Eventuali modifiche ai file del tema andrebbero perse con l’aggiornamento.', 'Creare un child theme prima di personalizzare o aggiornare il tema.', 5);
            }
        }
        if (count($inactive) >= 2) {
            $issues[] = self::issue('stack', 'inactive-themes', 'Temi inattivi', 'low', 'agenzia', count($inactive) . ' temi inattivi installati',
                'Anche i temi inattivi possono essere sfruttati se vulnerabili: ' . implode(', ', array_slice($inactive, 0, 5)) . (count($inactive) > 5 ? '…' : '') . '.',
                'Rimuovere i temi non usati, lasciando un tema predefinito di riserva.', 3);
        }
        $template_name = $template !== '' && isset($by_slug[$template]) ? (string) ($by_slug[$template]['name'] ?? $template) : '';
        return array('themes' => $rows, 'issues' => $issues, 'active' => $active, 'template' => $template, 'templateName' => $template_name);
    }
}
