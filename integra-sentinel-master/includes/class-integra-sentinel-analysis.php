<?php
/**
 * Integra Sentinel — analisi completa di un sito: dipendenze, temi, ambiente, integrita' del core,
 * pulizia dello stack ed eccezioni approvate, con un unico elenco di colli di bottiglia ordinato per impatto.
 *
 * Il contesto del parco (cataloghi, fonti esterne, impronte dei file) si calcola una volta per richiesta.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Analysis {

    private static $ctx = null;
    private static $memo = array();

    public static function reset() {
        self::$ctx = null;
        self::$memo = array();
    }

    public static function context() {
        if (self::$ctx !== null) { return self::$ctx; }
        $sites = Integra_Sentinel_Master::sites();
        $deps = Integra_Sentinel_Master::dependency_settings();
        // Catalogo delle ultime versioni: acquisti Envato abbinati per nome, poi il catalogo manuale (che prevale).
        $catalog = Integra_Sentinel_Intel::envato_catalog($sites, 'theme');
        foreach (Integra_Sentinel_Intel::envato_catalog($sites, 'plugin') as $slug => $entry) { $catalog[$slug] = $entry; }
        foreach ((array) $deps['catalog'] as $slug => $entry) {
            if (is_array($entry) && !empty($entry['version'])) {
                $catalog[$slug] = array('version' => (string) $entry['version'], 'url' => (string) ($entry['url'] ?? ''), 'source' => 'catalogo Integra');
            }
        }
        $plugin_hashes = array(); $theme_hashes = array(); $latest_wp = '';
        foreach ($sites as $site) {
            $r = (array) ($site['report'] ?? array());
            foreach (array((string) ($r['wp'] ?? ''), (string) ($r['core_update'] ?? '')) as $v) {
                if (preg_match('/^\d+\.\d+(\.\d+)?$/', $v) && ($latest_wp === '' || version_compare($v, $latest_wp, '>'))) { $latest_wp = $v; }
            }
            foreach ((array) ($r['plugins'] ?? array()) as $p) {
                if (!empty($p['hash']) && !empty($p['version']) && !empty($p['slug'])) {
                    $k = $p['slug'] . '|' . $p['version'];
                    $plugin_hashes[$k][$p['hash']] = ($plugin_hashes[$k][$p['hash']] ?? 0) + 1;
                }
            }
            foreach ((array) ($r['themes'] ?? array()) as $t) {
                if (!empty($t['hash']) && !empty($t['version']) && !empty($t['slug'])) {
                    $k = $t['slug'] . '|' . $t['version'];
                    $theme_hashes[$k][$t['hash']] = ($theme_hashes[$k][$t['hash']] ?? 0) + 1;
                }
            }
        }
        return self::$ctx = array(
            'sites' => $sites,
            'fleet' => Integra_Sentinel_Dependencies::fleet_versions($sites),
            'fleet_themes' => Integra_Sentinel_Dependencies::fleet_versions($sites, 'themes'),
            'verified' => self::verified_versions($sites),
            'catalog' => $catalog,
            'manual' => (array) $deps['rules'],
            'wporg' => Integra_Sentinel_Intel::wporg_info(),
            'checksums' => Integra_Sentinel_Intel::checksums(),
            'plugin_hashes' => $plugin_hashes,
            'theme_hashes' => $theme_hashes,
            'latest_wp' => $latest_wp,
            'bad' => (array) Integra_Sentinel_Settings::get('bad_versions'),
            'stack' => (array) Integra_Sentinel_Settings::get('stack'),
            'policies' => (array) Integra_Sentinel_Settings::get('policies'),
            'estimates' => (array) Integra_Sentinel_Settings::get('estimates'),
        );
    }

    /**
     * @return array|null graph, themes, environment, core, bottlenecks (attivi), waived, summary
     */
    public static function site($site, $groups = null) {
        if (empty($site['report']) || !is_array($site['report'])) { return null; }
        $id = (string) ($site['id'] ?? '');
        $key = $id . ':' . (int) ($site['last_seen'] ?? 0) . ':' . count((array) ($site['findings'] ?? array()));
        if (isset(self::$memo[$key])) { return self::$memo[$key]; }
        $ctx = self::context();
        $report = $site['report'];
        if ($groups === null) { $groups = Integra_Sentinel_Master::sn_group_findings($site['findings'] ?? array()); }

        $vulns = array(); $theme_vulns = array();
        foreach ((array) $groups as $g) {
            $fixed = '';
            foreach ((array) $g['items'] as $f) {
                if (!empty($f['fixed']) && ($fixed === '' || version_compare((string) $f['fixed'], $fixed, '>'))) { $fixed = (string) $f['fixed']; }
            }
            $entry = array('count' => (int) $g['count'], 'tier' => (string) $g['max_tier'], 'fixed' => $fixed);
            if (($g['type'] ?? '') === 'plugin') { $vulns[$g['slug']] = $entry; }
            elseif (($g['type'] ?? '') === 'theme') { $theme_vulns[$g['slug']] = $entry; }
        }
        $plugins = array();
        foreach ((array) ($report['plugins'] ?? array()) as $p) { if (!empty($p['slug'])) { $plugins[$p['slug']] = $p; } }
        $owners = array();
        $policies = $ctx['policies'];
        $env = (array) ($report['env'] ?? array());

        $themes = Integra_Sentinel_Themes::analyze($report, array('catalog' => $ctx['catalog'], 'fleet' => $ctx['fleet_themes'], 'verified' => $ctx['verified'], 'theme_hashes' => $ctx['theme_hashes'], 'vulns' => $theme_vulns, 'owners' => $owners));
        $graph = Integra_Sentinel_Dependencies::analyze($report, array(
            'fleet' => $ctx['fleet'], 'verified' => $ctx['verified'], 'catalog' => $ctx['catalog'], 'manual' => $ctx['manual'], 'vulns' => $vulns,
            'wporg' => $ctx['wporg'], 'integrity' => self::plugin_integrity($report, $ctx), 'owners' => $owners, 'bad' => $ctx['bad'],
            'quarantine' => array('days' => (int) ($policies['quarantine_days'] ?? 5), 'scope' => (string) ($policies['quarantine_scope'] ?? 'hubs')),
            'abandoned_years' => (int) ($policies['abandoned_years'] ?? 2),
            'auto' => (array) ($env['auto_updates']['plugins'] ?? array()),
            'bundle_theme' => (string) $themes['templateName'],
            'now' => time(),
        ));
        $has_envato = false;
        foreach (array_merge($graph['nodes'], $themes['themes']) as $n) { if (!empty($n['envato'])) { $has_envato = true; break; } }
        $environment = Integra_Sentinel_Environment::evaluate($report, array('policies' => $policies, 'meta' => (array) ($site['meta'] ?? array()), 'plugins' => $plugins, 'has_envato' => $has_envato));
        $core = self::core_integrity($report, $ctx);

        $issues = array_merge($graph['bottlenecks'], $themes['issues'], $environment['issues'], $core['issues'], self::stack_issues($report, $ctx), self::core_branch_issues($report, $ctx));
        $active = array(); $waived = array(); $minutes = 0;
        $nodes = array();
        foreach ($graph['nodes'] as $n) { $nodes[$n['id']] = $n; }
        $today = wp_date('Y-m-d');
        foreach ($issues as $b) {
            $b += array('node' => '', 'subject' => (string) $b['id'], 'subjectName' => (string) $b['title'], 'owner' => 'agenzia', 'security' => false, 'unlocks' => array(), 'score' => 0);
            $b['subject'] = (string) $b['subject'];
            $b['minutes'] = self::minutes_for($b, $ctx['estimates']);
            $b['sig'] = self::signature($b, $nodes);
            $b['ver'] = self::installed_version($b, $nodes, $themes);
            $mark = Integra_Sentinel_Settings::mark_for($id, (string) $b['kind'], $b['subject']);
            if ($mark) {
                $live = (string) ($mark['until'] ?? '') >= $today;
                // "Provato" e "in attesa" valgono per le versioni di quel momento: se cambiano, si riprova.
                if (in_array($mark['type'], array('tested', 'waiting'), true) && $mark['sig'] !== $b['sig']) { $live = false; }
                if ($live && $mark['type'] !== 'waiting') { $b['waiver'] = $mark; $waived[] = $b; continue; }
                if ($live || in_array($mark['type'], array('verified', 'tested'), true)) { $b['mark'] = $mark + array('live' => $live); }
            }
            $active[] = $b;
            $minutes += $b['minutes'];
        }
        usort($active, array(__CLASS__, 'compare'));

        $ready_themes = 0; $blocked_themes = 0; $theme_updates = 0;
        foreach ($themes['themes'] as $t) {
            if ($t['role'] === '' || $t['update'] === '') { continue; }
            ++$theme_updates;
            if ($t['status'] === 'update') { ++$ready_themes; } else { ++$blocked_themes; }
        }
        $gs = $graph['summary'];
        $minutes += ((int) $gs['ready'] + $ready_themes) * (int) ($ctx['estimates']['update'] ?? 3);
        $kinds = array(); $owner_counts = array(); $critical = 0; $security = 0;
        foreach ($active as $b) {
            $kinds[$b['kind']] = ($kinds[$b['kind']] ?? 0) + 1;
            $owner_counts[$b['owner']] = ($owner_counts[$b['owner']] ?? 0) + 1;
            if ($b['severity'] === 'critical') { ++$critical; }
            if (!empty($b['security'])) { ++$security; }
        }
        $summary = array(
            'ready' => (int) $gs['ready'] + $ready_themes,
            'blocked' => (int) $gs['blockedUpdates'] + $blocked_themes,
            'updates' => (int) $gs['updates'] + $theme_updates + (!empty($report['core_update']) ? 1 : 0),
            'license' => (int) ($kinds['license'] ?? 0),
            'manual' => (int) ($kinds['manual'] ?? 0) + (int) ($kinds['bundled'] ?? 0),
            'blind' => (int) ($kinds['blind'] ?? 0),
            'bottlenecks' => count($active),
            'critical' => $critical,
            'security' => $security,
            'waived' => count($waived),
            'quarantined' => (int) $gs['quarantined'],
            'kinds' => $kinds,
            'owners' => $owner_counts,
            'minutes' => $minutes,
        );
        return self::$memo[$key] = array('graph' => $graph, 'themes' => $themes, 'environment' => $environment, 'core' => $core,
            'bottlenecks' => $active, 'waived' => $waived, 'summary' => $summary);
    }

    /**
     * Versioni segnate a mano come "ultima versione" e ancora valide, per slug e nome: valgono da riferimento
     * anche per gli altri siti con lo stesso componente.
     */
    private static function verified_versions($sites) {
        $today = wp_date('Y-m-d');
        $out = array();
        foreach (Integra_Sentinel_Settings::waivers() as $w) {
            if (($w['type'] ?? '') !== 'verified' || (string) ($w['until'] ?? '') < $today || !Integra_Sentinel_Dependencies::valid_version((string) ($w['ver'] ?? ''))) { continue; }
            $key = (string) $w['subject'] . '|' . Integra_Sentinel_Dependencies::normalize_name((string) ($w['name'] ?? ''));
            if (isset($out[$key]) && !Integra_Sentinel_Dependencies::newer((string) $w['ver'], $out[$key]['version'])) { continue; }
            $site = ($w['site_id'] ?? '') === '*' ? 'un sito del parco' : (string) ($sites[$w['site_id']]['label'] ?? 'un sito del parco');
            $out[$key] = array('version' => (string) $w['ver'], 'site' => $site, 'date' => wp_date('d/m/Y', (int) ($w['at'] ?? time())));
        }
        return $out;
    }

    /** Versione installata del componente di un problema (plugin dalla mappa, tema dall'analisi dei temi). */
    private static function installed_version($b, $nodes, $themes) {
        if (isset($nodes[$b['node']])) { return (string) $nodes[$b['node']]['ver']; }
        if (strpos((string) ($b['id'] ?? ''), 'theme:') === 0) {
            foreach ($themes['themes'] as $t) { if ($t['slug'] === $b['subject']) { return (string) $t['version']; } }
        }
        return '';
    }

    /** Impronta delle versioni coinvolte in un problema: componente, versione installata e versioni in arrivo dei plugin collegati. */
    private static function signature($b, $nodes) {
        $parts = array((string) $b['kind'], (string) $b['subject'], (string) $b['title']);
        foreach (array_merge(array((string) $b['node']), (array) $b['unlocks']) as $nid) {
            if (isset($nodes[$nid])) { $parts[] = $nid . '@' . $nodes[$nid]['ver'] . '>' . $nodes[$nid]['new']; }
        }
        return substr(md5(implode('|', $parts)), 0, 12);
    }

    public static function compare($a, $b) {
        $rank = array('critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1);
        return (($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0)) ?: ((int) !empty($b['security']) <=> (int) !empty($a['security']))
            ?: ((int) $b['score'] <=> (int) $a['score']) ?: strcasecmp((string) $a['title'], (string) $b['title']);
    }

    /** Minuti stimati per tipo di intervento (Impostazioni → Stime). */
    public static function minutes_for($b, $estimates) {
        $map = array('security' => 'cluster', 'hold' => 'cluster', 'blocked' => 'cluster', 'unknown' => 'cluster', 'license' => 'license',
            'manual' => 'manual', 'bundled' => 'theme', 'blind' => 'manual', 'env' => 'env', 'server' => 'server', 'platform' => 'env',
            'missing' => 'missing', 'abandoned' => 'replace', 'modified' => 'modified', 'badversion' => 'update', 'theme' => 'theme',
            'postupdate' => 'postupdate', 'backup' => 'server', 'stack' => 'cleanup');
        return max(0, (int) ($estimates[$map[$b['kind']] ?? 'update'] ?? 10));
    }

    /** File principale diverso dal checksum ufficiale (WordPress.org) o dagli altri siti del parco con la stessa versione. */
    private static function plugin_integrity($report, $ctx) {
        $out = array();
        foreach ((array) ($report['plugins'] ?? array()) as $p) {
            $hash = strtolower((string) ($p['hash'] ?? ''));
            $slug = (string) ($p['slug'] ?? '');
            $version = (string) ($p['version'] ?? '');
            if ($hash === '' || $slug === '' || $version === '') { continue; }
            $key = $slug . '|' . $version;
            $file = (string) ($p['file'] ?? '');
            if (($p['channel']['type'] ?? '') === 'wporg') {
                if (strpos($file, '/') === false) { continue; }
                $rel = substr($file, strpos($file, '/') + 1);
                $entry = $ctx['checksums']['plugins'][$key] ?? null;
                if (is_array($entry) && ($entry['state'] ?? '') === 'ok' && !empty($entry['files'][$rel]) && !in_array($hash, (array) $entry['files'][$rel], true)) {
                    $out[$slug] = array('state' => 'wporg', 'note' => 'Il file principale ' . $rel . ' non corrisponde al checksum ufficiale WordPress.org della versione ' . $version . '.');
                }
                continue;
            }
            $counts = (array) ($ctx['plugin_hashes'][$key] ?? array());
            $total = array_sum($counts);
            if ($total >= 3 && (int) ($counts[$hash] ?? 0) === 1 && max($counts) >= 2) {
                $out[$slug] = array('state' => 'fleet', 'note' => 'Il file principale è diverso da quello degli altri ' . ($total - 1) . ' siti con la versione ' . $version . ': modifica locale, build diversa o versione craccata.');
            }
        }
        return $out;
    }

    /** Confronto dei file del core con i checksum ufficiali (scaricati dal Master). */
    public static function core_integrity($report, $ctx = null) {
        $ctx = $ctx ?: array('checksums' => Integra_Sentinel_Intel::checksums());
        $md5 = (array) ($report['core_md5'] ?? array());
        $version = (string) ($report['wp'] ?? '');
        $out = array('state' => 'unknown', 'mismatch' => array(), 'checked' => 0, 'issues' => array());
        if (!$md5) { return $out; }
        $entry = $ctx['checksums']['core'][$version] ?? null;
        if (!is_array($entry) || ($entry['state'] ?? '') !== 'ok') { $out['state'] = is_array($entry) ? 'unavailable' : 'pending'; return $out; }
        foreach ($md5 as $rel => $hash) {
            if (!isset($entry['files'][$rel])) { continue; }
            ++$out['checked'];
            if (!hash_equals((string) $entry['files'][$rel], strtolower((string) $hash))) { $out['mismatch'][] = (string) $rel; }
        }
        $out['state'] = $out['mismatch'] ? 'modified' : ($out['checked'] ? 'ok' : 'unavailable');
        if ($out['mismatch']) {
            $out['issues'][] = array('id' => 'core:modified', 'kind' => 'modified', 'subject' => 'wordpress-core', 'subjectName' => 'WordPress (core)',
                'severity' => 'critical', 'owner' => 'agenzia', 'title' => 'File del core di WordPress diversi dall’originale',
                'detail' => count($out['mismatch']) . ' file non corrispondono ai checksum ufficiali: ' . implode(', ', array_slice($out['mismatch'], 0, 5)) . '.',
                'action' => 'Trattare come possibile compromissione: reinstallare il core (bacheca o “wp core verify-checksums”), poi controllare accessi e backdoor.',
                'unlocks' => array(), 'security' => true, 'score' => 80, 'node' => '');
        }
        return $out;
    }

    /** WordPress fermo a due o più versioni maggiori indietro rispetto al resto del parco. */
    private static function core_branch_issues($report, $ctx) {
        $wp = (string) ($report['wp'] ?? '');
        $latest = (string) ($ctx['latest_wp'] ?? '');
        if (!preg_match('/^(\d+)\.(\d+)/', $wp, $a) || !preg_match('/^(\d+)\.(\d+)/', $latest, $b)) { return array(); }
        $behind = ((int) $b[1] * 10 + (int) $b[2]) - ((int) $a[1] * 10 + (int) $a[2]);
        if ($behind < 2) { return array(); }
        return array(array('id' => 'platform:wp-branch', 'kind' => 'platform', 'subject' => 'wp-' . $a[1] . '.' . $a[2], 'subjectName' => 'WordPress ' . $a[1] . '.' . $a[2],
            'severity' => $behind >= 4 ? 'high' : 'medium', 'owner' => 'agenzia', 'title' => 'WordPress ' . $a[1] . '.' . $a[2] . ': ' . $behind . ' versioni maggiori indietro',
            'detail' => 'Nel parco è già in uso WordPress ' . $latest . '. Le correzioni di sicurezza sono garantite solo per l’ultima versione maggiore.',
            'action' => 'Pianificare l’aggiornamento del core (backup, staging per i siti con molti addon).', 'unlocks' => array(), 'security' => false,
            'score' => 10 + 3 * $behind, 'node' => ''));
    }

    /** Categorie per riconoscere i doppioni: slug => (categoria, famiglia di prodotto). */
    public static function categories() {
        $map = array(
            'seo' => array('wordpress-seo' => 'yoast', 'wordpress-seo-premium' => 'yoast', 'seo-by-rank-math' => 'rankmath', 'seo-by-rank-math-pro' => 'rankmath',
                'all-in-one-seo-pack' => 'aioseo', 'all-in-one-seo-pack-pro' => 'aioseo', 'autodescription' => 'tsf', 'wp-seopress' => 'seopress', 'wp-seopress-pro' => 'seopress', 'squirrly-seo' => 'squirrly'),
            'cache' => array('wp-rocket' => 'rocket', 'w3-total-cache' => 'w3tc', 'wp-super-cache' => 'supercache', 'litespeed-cache' => 'litespeed', 'wp-fastest-cache' => 'wpfc',
                'sg-cachepress' => 'sgo', 'breeze' => 'breeze', 'comet-cache' => 'comet', 'hummingbird-performance' => 'hummingbird', 'cache-enabler' => 'enabler', 'nitropack' => 'nitropack', 'swift-performance-lite' => 'swift'),
            'security' => array('wordfence' => 'wordfence', 'better-wp-security' => 'solid', 'sucuri-scanner' => 'sucuri', 'all-in-one-wp-security-and-firewall' => 'aios',
                'ninjafirewall' => 'ninja', 'malcare-security' => 'malcare', 'defender-security' => 'defender', 'wp-simple-firewall' => 'shield', 'bulletproof-security' => 'bps'),
            'builder' => array('elementor' => 'elementor', 'elementor-pro' => 'elementor', 'js_composer' => 'wpbakery', 'beaver-builder-lite-version' => 'beaver', 'bb-plugin' => 'beaver',
                'divi-builder' => 'divi', 'brizy' => 'brizy', 'brizy-pro' => 'brizy', 'siteorigin-panels' => 'siteorigin', 'oxygen' => 'oxygen', 'thrive-visual-editor' => 'thrive', 'fusion-builder' => 'avada'),
            'multilingual' => array('sitepress-multilingual-cms' => 'wpml', 'polylang' => 'polylang', 'polylang-pro' => 'polylang', 'translatepress-multilingual' => 'translatepress', 'weglot' => 'weglot'),
            'cookie' => array('iubenda-cookie-law-solution' => 'iubenda', 'complianz-gdpr' => 'complianz', 'complianz-gdpr-premium' => 'complianz', 'cookiebot' => 'cookiebot',
                'cookie-law-info' => 'cookieyes', 'gdpr-cookie-compliance' => 'moove', 'real-cookie-banner' => 'rcb', 'real-cookie-banner-pro' => 'rcb', 'cookie-notice' => 'hu'),
            'smtp' => array('wp-mail-smtp' => 'wpmailsmtp', 'wp-mail-smtp-pro' => 'wpmailsmtp', 'post-smtp' => 'postsmtp', 'fluent-smtp' => 'fluentsmtp', 'easy-wp-smtp' => 'easysmtp', 'smtp-mailer' => 'smtpmailer'),
            'images' => array('imagify' => 'imagify', 'ewww-image-optimizer' => 'ewww', 'shortpixel-image-optimiser' => 'shortpixel', 'wp-smushit' => 'smush', 'optimole-wp' => 'optimole', 'tiny-compress-images' => 'tinypng'),
            'analytics' => array('google-site-kit' => 'sitekit', 'google-analytics-for-wordpress' => 'monsterinsights', 'ga-google-analytics' => 'gagoogle', 'google-analytics-dashboard-for-wp' => 'exactmetrics'),
            'forms' => array('contact-form-7' => 'cf7', 'wpforms-lite' => 'wpforms', 'wpforms' => 'wpforms', 'gravityforms' => 'gravity', 'ninja-forms' => 'ninja', 'formidable' => 'formidable', 'fluentform' => 'fluent', 'forminator' => 'forminator'),
        );
        $out = array();
        foreach ($map as $category => $slugs) { foreach ($slugs as $slug => $family) { $out[$slug] = array($category, $family); } }
        return $out;
    }

    private static function stack_issues($report, $ctx) {
        $labels = array('seo' => 'la SEO', 'cache' => 'la cache', 'security' => 'la sicurezza', 'builder' => 'il page builder', 'multilingual' => 'il multilingua',
            'cookie' => 'il banner cookie', 'smtp' => 'l’invio email (SMTP)', 'images' => 'l’ottimizzazione immagini', 'analytics' => 'le statistiche', 'forms' => 'i moduli');
        $severity = array('multilingual' => 'high', 'cookie' => 'medium', 'builder' => 'medium', 'cache' => 'medium', 'seo' => 'medium', 'smtp' => 'medium', 'analytics' => 'low',
            'security' => 'low', 'images' => 'low', 'forms' => 'low');
        $why = array('multilingual' => 'Due sistemi multilingua sullo stesso sito corrompono traduzioni e URL.', 'cookie' => 'Due banner cookie: consenso incoerente e rischio di non conformità.',
            'cache' => 'Due cache insieme causano pagine vecchie ed errori difficili da diagnosticare.', 'seo' => 'Meta tag e sitemap duplicati.',
            'smtp' => 'Si contendono l’invio delle email di WordPress.', 'builder' => 'Più page builder appesantiscono il sito e raddoppiano gli aggiornamenti critici.',
            'analytics' => 'Statistiche contate due volte.', 'security' => 'Firewall e scansioni sovrapposti.', 'images' => 'Ottimizzazioni ripetute sulle stesse immagini.',
            'forms' => 'Più plugin di moduli da mantenere e aggiornare.');
        $issues = array();
        $active = array(); $inactive = array();
        foreach ((array) ($report['plugins'] ?? array()) as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '' || Integra_Sentinel_Dependencies::is_own($p)) { continue; }
            if (!empty($p['active'])) { $active[$slug] = $p; } else { $inactive[$slug] = (string) ($p['name'] ?? $slug); }
        }
        $cats = self::categories(); $by_cat = array();
        foreach ($active as $slug => $p) {
            if (isset($cats[$slug])) { $by_cat[$cats[$slug][0]][$cats[$slug][1]] = (string) ($p['name'] ?? $slug); }
        }
        foreach ($by_cat as $cat => $families) {
            if (count($families) < 2) { continue; }
            $issues[] = array('id' => 'stack:dup-' . $cat, 'kind' => 'stack', 'subject' => 'dup-' . $cat, 'subjectName' => 'Doppioni: ' . $labels[$cat],
                'severity' => $severity[$cat] ?? 'low', 'owner' => 'agenzia', 'title' => 'Più plugin attivi per ' . $labels[$cat] . ': ' . implode(', ', $families),
                'detail' => $why[$cat] ?? 'Funzioni sovrapposte.', 'action' => 'Tenerne uno solo e rimuovere gli altri dopo aver migrato le impostazioni.', 'score' => 8);
        }
        if ($inactive) {
            $issues[] = array('id' => 'stack:inactive', 'kind' => 'stack', 'subject' => 'inactive-plugins', 'subjectName' => 'Plugin inattivi', 'severity' => 'low', 'owner' => 'agenzia',
                'title' => count($inactive) . ' plugin inattivi installati', 'detail' => 'Restano attaccabili anche se disattivati: ' . implode(', ', array_slice($inactive, 0, 6)) . (count($inactive) > 6 ? '…' : '') . '.',
                'action' => 'Rimuovere i plugin non usati.', 'score' => 3);
        }
        $stack = array_flip((array) $ctx['stack']);
        if ($stack) {
            $off = array();
            foreach ($active as $slug => $p) { if (!isset($stack[$slug])) { $off[] = (string) ($p['name'] ?? $slug); } }
            if ($off) {
                $issues[] = array('id' => 'stack:nonstandard', 'kind' => 'stack', 'subject' => 'nonstandard', 'subjectName' => 'Fuori dallo stack', 'severity' => 'low', 'owner' => 'agenzia',
                    'title' => count($off) . ' plugin fuori dallo stack dell’agenzia', 'detail' => implode(', ', array_slice($off, 0, 8)) . (count($off) > 8 ? '…' : '') . '.',
                    'action' => 'Sostituirli con plugin dello stack standard oppure approvarli in Impostazioni → Stack standard.', 'score' => 2);
            }
        }
        return $issues;
    }
}
