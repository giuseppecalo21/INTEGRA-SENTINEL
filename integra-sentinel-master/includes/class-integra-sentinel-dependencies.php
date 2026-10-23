<?php
/**
 * Integra Sentinel — motore di analisi delle dipendenze tra plugin.
 *
 * Classe pura (nessun output, nessuna chiamata remota, nessuna scrittura su DB): riceve l'inventario
 * GIA' sanificato dal Master e restituisce un grafo serializzabile con nuclei, colli di bottiglia,
 * origine dei plugin (WordPress.org, premium, Envato, su misura) e un piano di aggiornamento ordinato.
 *
 * Livelli di evidenza dei collegamenti (dal piu' forte):
 *  5 manuale      regola inserita dall'operatore nel Master
 *  4 dichiarata   header "Requires Plugins" o catena ufficiale (es. Elementor Pro → Elementor)
 *  3 header       header "WC tested up to", "Elementor tested up to", ecc.
 *  2 codice       firma dell'ecosistema trovata nel file principale del plugin (Client >= 1.6)
 *  1 nome         corrispondenza su slug/nome: indizio, mai prova
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Dependencies {

    const CONF_MANUAL   = 5;
    const CONF_DECLARED = 4;
    const CONF_HEADER   = 3;
    const CONF_CODE     = 2;
    const CONF_NAME     = 1;

    /** Gravita' degli stati di un collegamento: valori piu' alti prevalgono. */
    const EDGE_RANK = array('ok' => 0, 'coord' => 1, 'unknown' => 2, 'untested' => 2, 'order' => 3, 'hold' => 4, 'block' => 5);

    /** Gravita' degli stati di un nodo, usata per colori e ordinamenti. */
    const NODE_RANK = array('ok' => 0, 'update' => 1, 'blind' => 2, 'manual' => 2, 'warn' => 3, 'abandoned' => 3, 'held' => 4, 'license' => 5, 'bottleneck' => 5, 'blocked' => 6, 'missing' => 6);

    /**
     * Registro degli ecosistemi. "hubs" in ordine di priorita' (il primo installato e' il nucleo),
     * "chain" collega le estensioni ufficiali al plugin base, "match" riconosce gli addon per nome
     * (applicata a slug+nome normalizzati: minuscole, trattini e underscore sostituiti da spazi).
     * Le chiavi coincidono con le firme di codice inviate dal Client.
     */
    public static function ecosystems() {
        return array(
            'woocommerce' => array('name' => 'WooCommerce', 'hubs' => array('woocommerce'), 'tested' => 'wc_tested', 'requires' => 'wc_requires', 'match' => '~\b(woocommerce|woo|wc)\b~'),
            'elementor'   => array('name' => 'Elementor', 'hubs' => array('elementor', 'elementor-pro'), 'chain' => array('elementor-pro' => 'elementor'), 'tested' => 'elementor_tested', 'requires' => 'elementor_requires', 'match' => '~\belementor\b~'),
            'acf'         => array('name' => 'Advanced Custom Fields', 'hubs' => array('advanced-custom-fields-pro', 'advanced-custom-fields', 'secure-custom-fields'), 'match' => '~\b(acf|advanced custom fields)\b~'),
            'gravityforms'=> array('name' => 'Gravity Forms', 'hubs' => array('gravityforms'), 'match' => '~\b(gravity ?forms|gf)\b~'),
            'wpml'        => array('name' => 'WPML', 'hubs' => array('sitepress-multilingual-cms'), 'match' => '~\bwpml\b~'),
            'cf7'         => array('name' => 'Contact Form 7', 'hubs' => array('contact-form-7'), 'match' => '~\b(contact form 7|cf7|wpcf7)\b~'),
            'wpbakery'    => array('name' => 'WPBakery', 'hubs' => array('js_composer'), 'match' => '~\b(wpbakery|visual composer|vc)\b~'),
            'edd'         => array('name' => 'Easy Digital Downloads', 'hubs' => array('easy-digital-downloads', 'easy-digital-downloads-pro'), 'match' => '~\b(edd|easy digital downloads)\b~'),
            'buddypress'  => array('name' => 'BuddyPress', 'hubs' => array('buddypress', 'buddyboss-platform'), 'match' => '~\b(buddypress|buddyboss)\b~'),
            'learndash'   => array('name' => 'LearnDash', 'hubs' => array('sfwd-lms'), 'match' => '~\blearndash\b~'),
            'polylang'    => array('name' => 'Polylang', 'hubs' => array('polylang-pro', 'polylang'), 'match' => '~\bpolylang\b~'),
            'wpforms'     => array('name' => 'WPForms', 'hubs' => array('wpforms', 'wpforms-lite'), 'match' => '~\bwpforms\b~'),
            'tec'         => array('name' => 'The Events Calendar', 'hubs' => array('the-events-calendar'), 'match' => '~\b(events calendar|tribe)\b~'),
            'yoast'       => array('name' => 'Yoast SEO', 'hubs' => array('wordpress-seo', 'wordpress-seo-premium'), 'chain' => array('wordpress-seo-premium' => 'wordpress-seo'), 'match' => '~\byoast\b~'),
            'rankmath'    => array('name' => 'Rank Math', 'hubs' => array('seo-by-rank-math', 'seo-by-rank-math-pro'), 'chain' => array('seo-by-rank-math-pro' => 'seo-by-rank-math'), 'match' => '~\brank ?math\b~'),
            'beaver'      => array('name' => 'Beaver Builder', 'hubs' => array('bb-plugin', 'beaver-builder-lite-version'), 'match' => '~\bbeaver builder\b~'),
            'revslider'   => array('name' => 'Slider Revolution', 'hubs' => array('revslider'), 'match' => '~\b(revslider|slider revolution|revolution slider)\b~'),
            'memberpress' => array('name' => 'MemberPress', 'hubs' => array('memberpress'), 'match' => '~\b(memberpress|mepr)\b~'),
            'ninjaforms'  => array('name' => 'Ninja Forms', 'hubs' => array('ninja-forms'), 'match' => '~\bninja forms\b~'),
        );
    }

    /** Slug di prodotti tipicamente venduti su Envato/CodeCanyon o inclusi nei temi ThemeForest. */
    public static function envato_slugs() {
        return array('revslider', 'js_composer', 'layerslider', 'essential-grid', 'ultimate_vc_addons', 'masterslider', 'go_pricing',
            'convertplug', 'mega_main_menu', 'envato-market', 'slider-revolution', 'ultimate-addons-for-visual-composer', 'wpbakery-page-builder',
            'the-grid', 'modal-popup-box', 'ninja-popups', 'dt-the7-core', 'flatsome-addons', 'woocommerce-products-filter-pro');
    }

    /** Campi aggiunti nella 1.8, presenti anche sui nodi "mancante" e "ambiente". */
    private static function extra_fields() {
        return array('license' => '', 'licenseExp' => '', 'owner' => '', 'blind' => false, 'abandoned' => '', 'abandonedNote' => '',
            'modified' => '', 'modifiedNote' => '', 'bad' => '', 'badTarget' => '', 'quarantine' => '', 'auto' => false, 'bundleTheme' => '');
    }

    public static function valid_version($v) {
        return is_string($v) && $v !== '' && strlen($v) <= 40 && preg_match('/^v?\d+(?:\.\d+){0,4}(?:[.+_-]?[a-z0-9]+)*$/i', $v) === 1;
    }

    private static function clean_version($v) {
        return ltrim((string) $v, 'vV');
    }

    private static function newer($a, $b) {
        if (!self::valid_version($a)) { return false; }
        if (!self::valid_version($b)) { return true; }
        return version_compare(self::clean_version($a), self::clean_version($b), '>');
    }

    /** Confronto major.minor: "tested up to 9.4" copre 9.4.x ma non 9.5. */
    private static function minor_greater($version, $tested) {
        $a = self::major_minor($version);
        $b = self::major_minor($tested);
        if ($a === null || $b === null) { return false; }
        return $a[0] > $b[0] || ($a[0] === $b[0] && $a[1] > $b[1]);
    }

    private static function major_minor($v) {
        if (!preg_match_all('/\d+/', (string) $v, $m) || empty($m[0])) { return null; }
        return array((int) $m[0][0], isset($m[0][1]) ? (int) $m[0][1] : 0);
    }

    private static function host_of($url) {
        $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
        return preg_match('/^[a-z0-9.-]{1,120}$/', $host) ? $host : '';
    }

    public static function normalize_name($name) {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
    }

    /**
     * Versioni piu' recenti viste nell'intero parco siti, per slug e nome normalizzato.
     * Il nome deve coincidere: due plugin "su misura" con lo stesso slug non si influenzano.
     */
    public static function fleet_versions($sites) {
        $out = array();
        foreach ((array) $sites as $site) {
            if (!is_array($site)) { continue; }
            $label = (string) ($site['label'] ?? '');
            foreach ((array) ($site['report']['plugins'] ?? array()) as $p) {
                $slug = (string) ($p['slug'] ?? '');
                if ($slug === '') { continue; }
                $key = $slug . '|' . self::normalize_name($p['name'] ?? '');
                foreach (array((string) ($p['version'] ?? ''), (string) ($p['update']['version'] ?? '')) as $v) {
                    if (!self::valid_version($v)) { continue; }
                    if (!isset($out[$key]) || self::newer($v, $out[$key]['version'])) {
                        $out[$key] = array('version' => $v, 'site' => $label);
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Analizza l'inventario di un sito.
     * $ctx: fleet, catalog, manual, vulns; dalla 1.8 anche wporg (info WordPress.org), integrity (file principale
     * diverso dall'originale), owners (chi possiede la licenza), bad (versioni da evitare), quarantine, abandoned_years,
     * auto (slug con aggiornamento automatico), bundle_theme (tema che include i plugin TGMPA), now.
     */
    public static function analyze($report, $ctx = array()) {
        $report = is_array($report) ? $report : array();
        $fleet   = (array) ($ctx['fleet'] ?? array());
        $catalog = (array) ($ctx['catalog'] ?? array());
        $manual  = (array) ($ctx['manual'] ?? array());
        $vulns   = (array) ($ctx['vulns'] ?? array());
        $wporg   = (array) ($ctx['wporg'] ?? array());
        $integrity = (array) ($ctx['integrity'] ?? array());
        $owners  = (array) ($ctx['owners'] ?? array());
        $bad     = (array) ($ctx['bad'] ?? array());
        $quarantine = (array) ($ctx['quarantine'] ?? array());
        $stale_years = max(1, (int) ($ctx['abandoned_years'] ?? 2));
        $auto    = array_flip(array_map('strval', (array) ($ctx['auto'] ?? array())));
        $bundle_theme = (string) ($ctx['bundle_theme'] ?? '');
        $now     = (int) ($ctx['now'] ?? time());
        $ecos    = self::ecosystems();

        $plugins = array();
        foreach ((array) ($report['plugins'] ?? array()) as $p) {
            if (!is_array($p) || empty($p['slug']) || isset($plugins[$p['slug']])) { continue; }
            $plugins[(string) $p['slug']] = $p;
        }
        $bundled = array();
        foreach ((array) ($report['bundled'] ?? array()) as $b) {
            if (is_array($b) && !empty($b['slug'])) { $bundled[(string) $b['slug']] = $b; }
        }

        // 1. Nodi.
        $nodes = array();
        foreach ($plugins as $slug => $p) {
            $origin = self::origin($p, $bundled);
            $latest = self::latest_known($p, $fleet, $catalog, $bundled);
            $node = array(
                'id' => $slug, 'name' => (string) ($p['name'] ?? $slug), 'ver' => (string) ($p['version'] ?? ''),
                'new' => (string) ($p['update']['version'] ?? ''), 'latest' => $latest['version'], 'latestSrc' => $latest['source'],
                'catalogUrl' => $latest['url'],
                'active' => !empty($p['active']), 'origin' => $origin['key'], 'originLabel' => $origin['label'],
                'originHint' => $origin['hint'], 'premium' => $origin['premium'], 'envato' => $origin['envato'],
                'vuln' => isset($vulns[$slug]) ? $vulns[$slug] : null,
                'role' => 'solo', 'cluster' => '', 'parent' => '', 'depth' => 0, 'eco' => '',
                'status' => 'ok', 'statusLabel' => '', 'reasons' => array(), 'score' => 0,
                'holds' => array(), 'softHolds' => array(), 'heldBy' => array(), 'softHeldBy' => array(),
                'needsFirst' => array(), 'blockers' => array(),
                'license' => '', 'licenseExp' => '', 'owner' => (string) ($owners[$slug] ?? ''), 'blind' => false,
                'abandoned' => '', 'abandonedNote' => '', 'modified' => '', 'modifiedNote' => '', 'bad' => '', 'badTarget' => '',
                'quarantine' => '', 'auto' => isset($auto[$slug]), 'bundleTheme' => $origin['key'] === 'bundled' ? $bundle_theme : '',
            );

            // Licenza: stato letto dal Client (mai la chiave) oppure aggiornamento visibile ma pacchetto non scaricabile.
            $lic = is_array($p['license'] ?? null) ? $p['license'] : array();
            $lic_state = (string) ($lic['status'] ?? '');
            if (in_array($lic_state, array('missing', 'expired', 'invalid'), true)) {
                $node['license'] = $lic_state;
                $node['licenseExp'] = (string) ($lic['expires'] ?? '');
            } elseif ($node['new'] !== '' && (int) ($p['update']['pkg'] ?? -1) === 0) {
                $node['license'] = 'nopkg';
            }

            // Punto cieco: premium senza canale e senza alcuna versione di riferimento autorevole.
            $node['blind'] = !empty($origin['premium']) && in_array($origin['key'], array('nochannel', 'bundled'), true) && $node['new'] === '' && empty($latest['known']);

            // Plugin chiusi o fermi su WordPress.org (solo se l'identita' coincide).
            $info = $wporg[$slug] ?? null;
            if (is_array($info)) {
                $channel_type = (string) ($p['channel']['type'] ?? '');
                $uri_wporg = preg_match('/(^|\.)wordpress\.org$/', self::host_of($p['plugin_uri'] ?? '')) === 1;
                $same_name = self::normalize_name($info['name'] ?? '') === self::normalize_name($p['name'] ?? '');
                $applies = $channel_type === 'wporg' || (in_array($channel_type, array('none', 'unknown', ''), true) && ($uri_wporg || $same_name));
                if ($applies && ($info['state'] ?? '') === 'closed') {
                    $node['abandoned'] = 'closed';
                    $node['abandonedNote'] = 'Chiuso su WordPress.org' . (!empty($info['closed_date']) ? ' dal ' . gmdate('d/m/Y', (int) strtotime((string) $info['closed_date'])) : '')
                        . (!empty($info['reason']) ? ' (' . $info['reason'] . ')' : '') . ': non riceverà più correzioni.';
                } elseif ($applies && ($info['state'] ?? '') === 'ok' && !empty($info['last_updated'])) {
                    $updated = strtotime((string) $info['last_updated']);
                    if ($updated && $now - $updated > $stale_years * YEAR_IN_SECONDS) {
                        $node['abandoned'] = 'stale';
                        $node['abandonedNote'] = 'Nessun aggiornamento su WordPress.org dal ' . gmdate('d/m/Y', $updated) . ': probabilmente abbandonato.';
                    }
                }
            }

            // File principale diverso dall'originale WordPress.org o dagli altri siti con la stessa versione.
            if (isset($integrity[$slug]) && is_array($integrity[$slug])) {
                $node['modified'] = (string) ($integrity[$slug]['state'] ?? '');
                $node['modifiedNote'] = (string) ($integrity[$slug]['note'] ?? '');
            }

            // Versioni segnalate dall'agenzia come da evitare.
            foreach ($bad as $rule) {
                if (!is_array($rule) || (string) ($rule[0] ?? '') !== $slug) { continue; }
                $note = (string) ($rule[2] ?? '') !== '' ? (string) $rule[2] : 'versione segnalata dall’agenzia';
                if ($node['bad'] === '' && Integra_Sentinel_Settings::version_in_range($node['ver'], (string) ($rule[1] ?? ''))) { $node['bad'] = $note; }
                if ($node['new'] !== '' && $node['badTarget'] === '' && Integra_Sentinel_Settings::version_in_range($node['new'], (string) ($rule[1] ?? ''))) { $node['badTarget'] = $note; }
            }
            $nodes[$slug] = $node;
        }

        // Nucleo principale installato per ciascun ecosistema.
        $eco_hub = array(); $hub_eco = array();
        foreach ($ecos as $key => $eco) {
            foreach ($eco['hubs'] as $h) {
                if (!isset($nodes[$h])) { continue; }
                if (!isset($eco_hub[$key])) { $eco_hub[$key] = $h; }
                $hub_eco[$h] = $key;
            }
        }

        // 2. Collegamenti con evidenze.
        $edges = array();
        $add = function($from, $to, $conf, $evidence) use (&$edges, &$nodes) {
            if ($from === $to || !isset($nodes[$from]) || !isset($nodes[$to])) { return; }
            $k = $from . '>' . $to;
            if (!isset($edges[$k])) {
                $edges[$k] = array('s' => $from, 't' => $to, 'conf' => $conf, 'evidence' => array(), 'state' => 'ok', 'notes' => array(), 'tested' => '', 'requires' => '');
            }
            $edges[$k]['conf'] = max($edges[$k]['conf'], $conf);
            if (!in_array($evidence, $edges[$k]['evidence'], true)) { $edges[$k]['evidence'][] = $evidence; }
        };

        $missing = array();
        foreach ($plugins as $slug => $p) {
            foreach ((array) ($p['requires_plugins'] ?? array()) as $req) {
                $req = (string) $req;
                if ($req === '' || $req === $slug) { continue; }
                if (!isset($nodes[$req])) { $missing[$req][] = $slug; continue; }
                $add($slug, $req, self::CONF_DECLARED, 'Requires Plugins');
            }
            $own_eco = $hub_eco[$slug] ?? '';
            foreach ($ecos as $key => $eco) {
                if (!isset($eco_hub[$key])) { continue; }
                $hub = $eco_hub[$key];
                if ($own_eco === $key) {
                    if (!empty($eco['chain'][$slug]) && isset($nodes[$eco['chain'][$slug]])) {
                        $add($slug, $eco['chain'][$slug], self::CONF_DECLARED, 'Estensione ufficiale');
                    }
                    continue;
                }
                if ((!empty($eco['tested']) && !empty($p[$eco['tested']])) || (!empty($eco['requires']) && !empty($p[$eco['requires']]))) {
                    $add($slug, $hub, self::CONF_HEADER, 'Header ' . $eco['name']);
                }
                if (in_array($key, (array) ($p['couplings'] ?? array()), true)) {
                    $add($slug, $hub, self::CONF_CODE, 'Codice ' . $eco['name']);
                }
                $hay = strtolower(str_replace(array('-', '_'), ' ', $slug . ' ' . ($p['name'] ?? '')));
                if (preg_match($eco['match'], $hay)) {
                    $add($slug, $hub, self::CONF_NAME, 'Nome');
                }
            }
            if (!empty($p['elementor_pro_tested']) && isset($nodes['elementor-pro']) && $slug !== 'elementor-pro') {
                $add($slug, 'elementor-pro', self::CONF_HEADER, 'Header Elementor Pro');
            }
        }
        foreach ($manual as $rule) {
            if (is_array($rule) && isset($rule[0], $rule[1])) { $add((string) $rule[0], (string) $rule[1], self::CONF_MANUAL, 'Regola manuale'); }
        }

        // Plugin dichiarati come richiesti ma non installati: nodi fantasma.
        foreach ($missing as $req => $by) {
            $nodes[$req] = array(
                'id' => $req, 'name' => $req, 'ver' => '', 'new' => '', 'latest' => '', 'latestSrc' => '', 'catalogUrl' => '', 'active' => false,
                'origin' => 'missing', 'originLabel' => 'Non installato', 'originHint' => '', 'premium' => false, 'envato' => false, 'vuln' => null,
                'role' => 'missing', 'cluster' => '', 'parent' => '', 'depth' => 0, 'eco' => '', 'status' => 'missing', 'statusLabel' => '',
                'reasons' => array('Richiesto da ' . self::names($nodes, $by) . ' ma non installato.'), 'score' => 0,
                'holds' => array(), 'softHolds' => array(), 'heldBy' => array(), 'softHeldBy' => array(), 'needsFirst' => array(), 'blockers' => array(),
            ) + self::extra_fields();
            foreach ($by as $slug) {
                $k = $slug . '>' . $req;
                $edges[$k] = array('s' => $slug, 't' => $req, 'conf' => self::CONF_DECLARED, 'evidence' => array('Requires Plugins'), 'state' => 'block',
                    'notes' => array('Dipendenza dichiarata non installata: ' . $req . '.'), 'tested' => '', 'requires' => '');
                $nodes[$slug]['blockers'][] = 'Manca il plugin richiesto ' . $req . '.';
            }
        }

        // 3. Albero: ciascun nodo ha un genitore principale (collegamento piu' forte).
        $parent = array();
        foreach ($edges as $e) {
            $s = $e['s'];
            if (!isset($parent[$s]) || $e['conf'] > $parent[$s]['conf']
                || ($e['conf'] === $parent[$s]['conf'] && isset($hub_eco[$e['t']]) && !isset($hub_eco[$parent[$s]['t']]))) {
                $parent[$s] = array('t' => $e['t'], 'conf' => $e['conf']);
            }
        }
        $children = array();
        foreach ($nodes as $id => &$n) {
            $chain = array($id => true); $cur = $id; $depth = 0;
            while (isset($parent[$cur]) && $depth < 8) {
                $next = $parent[$cur]['t'];
                if (isset($chain[$next])) { unset($parent[$cur]); break; } // ciclo: interrompe al nodo corrente
                $chain[$next] = true; $cur = $next; ++$depth;
            }
            $n['cluster'] = $cur;
            $n['depth'] = $depth;
            $n['parent'] = isset($parent[$id]) ? $parent[$id]['t'] : '';
        }
        unset($n);
        foreach ($nodes as $id => $n) {
            if ($n['parent'] !== '') { $children[$n['parent']][] = $id; }
        }
        foreach ($nodes as $id => &$n) {
            if ($n['role'] === 'missing') { continue; }
            $has_children = !empty($children[$id]);
            if ($n['parent'] === '' && $has_children) { $n['role'] = 'hub'; }
            elseif ($n['parent'] !== '' && $has_children) { $n['role'] = 'subhub'; }
            elseif ($n['parent'] !== '') { $n['role'] = 'addon'; }
            else { $n['role'] = 'solo'; $n['cluster'] = ''; }
            $n['eco'] = $hub_eco[$n['cluster']] ?? '';
        }
        unset($n);

        // 4. Valutazione dei collegamenti rispetto agli aggiornamenti.
        foreach ($edges as $k => &$e) {
            if ($e['state'] === 'block' && isset($missing[$e['t']])) { continue; }
            $a = &$nodes[$e['s']];
            $h = &$nodes[$e['t']];
            $a_plugin = $plugins[$e['s']] ?? array();
            list($tested, $requires) = self::edge_headers($a_plugin, $e['t'], $hub_eco, $ecos);
            $e['tested'] = $tested; $e['requires'] = $requires;
            $state = 'ok'; $notes = array();
            $a_can_move = $a['new'] !== '' || ($a['latest'] !== '' && self::newer($a['latest'], $a['ver']));

            if ($requires !== '' && self::valid_version($h['ver']) && version_compare(self::clean_version($h['ver']), self::clean_version($requires), '<')) {
                if ($h['new'] !== '' && !version_compare(self::clean_version($h['new']), self::clean_version($requires), '<')) {
                    $state = self::worse($state, 'order');
                    $notes[] = 'Richiede ' . $h['name'] . ' ≥ ' . $requires . ': aggiornare prima ' . $h['name'] . ' alla ' . $h['new'] . '.';
                    $a['needsFirst'][] = $e['t'];
                } else {
                    $state = self::worse($state, 'block');
                    $notes[] = 'Richiede ' . $h['name'] . ' ≥ ' . $requires . ' (installata ' . $h['ver'] . ') e nessun aggiornamento disponibile lo soddisfa.';
                    $a['blockers'][] = 'Richiede ' . $h['name'] . ' ≥ ' . $requires . '.';
                }
            }
            if ($h['new'] !== '') {
                if (!$a['active']) {
                    $notes[] = 'Addon inattivo: non blocca ' . $h['name'] . ' ' . $h['new'] . ', ma valutarne la rimozione.';
                } elseif ($tested !== '') {
                    if (self::minor_greater($h['new'], $tested)) {
                        if ($a_can_move) {
                            $state = self::worse($state, 'coord');
                            $notes[] = 'Testato fino a ' . $h['name'] . ' ' . $tested . '; esiste un aggiornamento dell\'addon: aggiornarli nella stessa finestra e verificare.';
                        } else {
                            $state = self::worse($state, 'hold');
                            $notes[] = 'Testato solo fino a ' . $h['name'] . ' ' . $tested . ' e senza aggiornamenti: trattiene ' . $h['name'] . ' ' . $h['ver'] . ' → ' . $h['new'] . '.';
                            $a['holds'][] = $e['t'];
                            $h['heldBy'][] = $e['s'];
                        }
                    } else {
                        $notes[] = 'Compatibilità dichiarata fino a ' . $h['name'] . ' ' . $tested . '.';
                    }
                } elseif ($a_can_move) {
                    $state = self::worse($state, 'coord');
                    $notes[] = 'Compatibilità con ' . $h['name'] . ' ' . $h['new'] . ' non dichiarata; l\'addon ha un aggiornamento: aggiornarli insieme.';
                } elseif ($e['conf'] >= self::CONF_CODE || $a['premium']) {
                    $state = self::worse($state, 'unknown');
                    $notes[] = 'Compatibilità con ' . $h['name'] . ' ' . $h['new'] . ' non dichiarata e nessun aggiornamento dell\'addon: verificare in staging.';
                    $a['softHolds'][] = $e['t'];
                    $h['softHeldBy'][] = $e['s'];
                } else {
                    $state = self::worse($state, 'coord');
                    $notes[] = 'Collegamento solo probabile (nome): verificare ' . $a['name'] . ' dopo l\'aggiornamento di ' . $h['name'] . '.';
                }
            } elseif ($tested !== '' && $a['active'] && $h['ver'] !== '' && self::minor_greater($h['ver'], $tested)) {
                $state = self::worse($state, 'untested');
                $notes[] = 'In uso con ' . $h['name'] . ' ' . $h['ver'] . ', oltre la versione testata ' . $tested . '.';
            }
            if (!$a['active'] && $state !== 'block') { $state = $state === 'hold' ? 'unknown' : $state; }
            $e['state'] = $state; $e['notes'] = $notes;
            unset($a, $h);
        }
        unset($e);

        // 5. Ambiente: WordPress e PHP richiesti dagli aggiornamenti.
        $env_nodes = array();
        $wp = (string) ($report['wp'] ?? ''); $php = (string) ($report['php'] ?? ''); $core_update = (string) ($report['core_update'] ?? '');
        foreach ($plugins as $slug => $p) {
            if (empty($p['update']['version'])) { continue; }
            $need_wp = (string) ($p['update']['requires_wp'] ?? '');
            $need_php = (string) ($p['update']['requires_php'] ?? '');
            if ($need_wp !== '' && self::valid_version($wp) && self::valid_version($need_wp) && version_compare($wp, $need_wp, '<')) {
                $ok_core = $core_update !== '' && !version_compare($core_update, $need_wp, '<');
                $env_nodes['env:wp'][] = array($slug, $need_wp, $ok_core);
                if ($ok_core) { $nodes[$slug]['needsFirst'][] = 'env:wp'; }
                else { $nodes[$slug]['blockers'][] = 'La nuova versione richiede WordPress ' . $need_wp . ' (installato ' . $wp . ').'; }
            }
            if ($need_php !== '' && self::valid_version($php) && self::valid_version($need_php) && version_compare($php, $need_php, '<')) {
                $env_nodes['env:php'][] = array($slug, $need_php, false);
                $nodes[$slug]['blockers'][] = 'La nuova versione richiede PHP ' . $need_php . ' (server ' . $php . ').';
            }
        }
        foreach ($env_nodes as $env_id => $list) {
            $is_php = $env_id === 'env:php';
            $max_need = '';
            foreach ($list as $item) { if ($max_need === '' || version_compare($item[1], $max_need, '>')) { $max_need = $item[1]; } }
            $nodes[$env_id] = array(
                'id' => $env_id, 'name' => $is_php ? 'PHP ' . $php : 'WordPress ' . $wp, 'ver' => $is_php ? $php : $wp,
                'new' => $is_php ? '' : $core_update, 'latest' => '', 'latestSrc' => '', 'catalogUrl' => '', 'active' => true,
                'origin' => 'env', 'originLabel' => $is_php ? 'Server (hosting)' : 'WordPress Core', 'originHint' => '', 'premium' => false, 'envato' => false,
                'vuln' => null, 'role' => 'env', 'cluster' => 'env', 'parent' => '', 'depth' => 0, 'eco' => '',
                'status' => $is_php ? 'bottleneck' : ($core_update !== '' ? 'warn' : 'bottleneck'), 'statusLabel' => '',
                'reasons' => array(($is_php ? 'PHP ' . $php : 'WordPress ' . $wp) . ' blocca ' . count($list) . ' aggiornamenti (serve ≥ ' . $max_need . ').'),
                'score' => 0, 'holds' => array(), 'softHolds' => array(), 'heldBy' => array(), 'softHeldBy' => array(), 'needsFirst' => array(), 'blockers' => array(),
                'need' => $max_need, 'blocked' => array_map(function($i) { return $i[0]; }, $list),
            ) + self::extra_fields();
            foreach ($list as $item) {
                $edges[$item[0] . '>' . $env_id] = array('s' => $item[0], 't' => $env_id, 'conf' => self::CONF_DECLARED, 'evidence' => array('Requisito aggiornamento'),
                    'state' => $item[2] ? 'order' : 'block', 'tested' => '', 'requires' => $item[1],
                    'notes' => array($item[2] ? 'Aggiornare prima WordPress alla ' . $core_update . '.' : 'Serve ' . ($is_php ? 'PHP ' : 'WordPress ') . $item[1] . '.'));
            }
        }

        // 6. Stato dei nodi e punteggio di collo di bottiglia.
        foreach ($nodes as $id => &$n) {
            if ($n['role'] === 'env') { continue; }
            if ($n['role'] === 'missing') { $n['statusLabel'] = 'Mancante'; continue; }
            $n['holds'] = array_values(array_unique($n['holds'])); $n['heldBy'] = array_values(array_unique($n['heldBy']));
            $n['softHolds'] = array_values(array_unique($n['softHolds'])); $n['softHeldBy'] = array_values(array_unique($n['softHeldBy']));
            $n['needsFirst'] = array_values(array_unique($n['needsFirst']));
            $behind = $n['latest'] !== '' && self::newer($n['latest'], $n['ver']);
            $reasons = $n['blockers'];
            foreach ($n['holds'] as $h) {
                $reasons[] = 'Trattiene ' . $nodes[$h]['name'] . ' ' . $nodes[$h]['ver'] . ' → ' . $nodes[$h]['new'] . ' (non testato con la nuova versione).';
            }
            if ($n['heldBy']) { $reasons[] = 'Aggiornamento trattenuto da ' . self::names($nodes, $n['heldBy']) . '.'; }
            if ($n['softHeldBy']) { $reasons[] = 'Compatibilità non dichiarata da ' . self::names($nodes, $n['softHeldBy']) . '.'; }
            foreach ($n['softHolds'] as $h) { $reasons[] = 'Compatibilità con ' . $nodes[$h]['name'] . ' ' . $nodes[$h]['new'] . ' non dichiarata.'; }
            foreach ($n['needsFirst'] as $f) { $reasons[] = 'Aggiornare prima ' . ($nodes[$f]['name'] ?? $f) . '.'; }
            if ($n['new'] === '' && $behind && $n['premium']) {
                $reasons[] = 'Disponibile ' . $n['latest'] . ' (' . $n['latestSrc'] . '): aggiornamento manuale dal fornitore.';
            }
            if ($n['vuln'] && !empty($n['vuln']['count'])) {
                $reasons[] = (int) $n['vuln']['count'] . ' vulnerabilità note sulla versione installata' . (!empty($n['vuln']['fixed']) ? ' (corrette dalla ' . $n['vuln']['fixed'] . ')' : '') . '.';
            }
            $has_vuln = !empty($n['vuln']['count']);

            // Quarantena: le nuove versioni x.y.0 dei nuclei si lasciano "maturare" qualche giorno, salvo patch di sicurezza.
            if ($n['new'] !== '' && !$has_vuln && !empty($quarantine['days']) && ($quarantine['scope'] ?? 'hubs') !== 'off'
                && (($quarantine['scope'] ?? 'hubs') === 'all' || isset($hub_eco[$id]))) {
                $info = $wporg[$id] ?? null;
                if (is_array($info) && ($info['state'] ?? '') === 'ok' && (string) ($info['version'] ?? '') === $n['new']
                    && preg_match('/^\d+\.\d+(?:\.0)?$/', ltrim($n['new'], 'vV')) && !empty($info['last_updated'])) {
                    $released = strtotime((string) $info['last_updated']);
                    $until = $released ? $released + (int) $quarantine['days'] * DAY_IN_SECONDS : 0;
                    if ($until > $now) { $n['quarantine'] = gmdate('Y-m-d', $until); }
                }
            }
            $license_text = array(
                'missing' => 'Chiave di licenza non inserita nel sito: niente aggiornamenti dal fornitore.',
                'expired' => 'Licenza scaduta' . ($n['licenseExp'] !== '' && $n['licenseExp'] !== 'lifetime' ? ' il ' . gmdate('d/m/Y', (int) strtotime($n['licenseExp'])) : '') . ': niente aggiornamenti dal fornitore.',
                'invalid' => 'Licenza non valida o non attivata su questo sito.',
                'nopkg' => 'Aggiornamento ' . $n['new'] . ' visibile ma non scaricabile: licenza mancante o scaduta.',
            );
            if ($n['license'] !== '') { $reasons[] = $license_text[$n['license']] ?? 'Licenza da verificare.'; }
            if ($n['bad'] !== '') { $reasons[] = 'Versione installata da evitare: ' . $n['bad'] . '.'; }
            if ($n['badTarget'] !== '') { $reasons[] = 'Non aggiornare a ' . $n['new'] . ': ' . $n['badTarget'] . '.'; }
            if ($n['quarantine'] !== '') { $reasons[] = 'Versione ' . $n['new'] . ' appena rilasciata: attendere fino al ' . gmdate('d/m', (int) strtotime($n['quarantine'])) . ' (quarantena delle nuove versioni).'; }
            if ($n['abandonedNote'] !== '') { $reasons[] = $n['abandonedNote']; }
            if ($n['modifiedNote'] !== '') { $reasons[] = $n['modifiedNote']; }
            if ($n['bundleTheme'] !== '') { $reasons[] = 'Incluso nel tema ' . $n['bundleTheme'] . ': si aggiorna con il pacchetto del tema.'; }
            if ($n['blind'] && !$behind) { $reasons[] = 'Nessun canale di aggiornamento e nessuna versione di riferimento: impossibile sapere se è aggiornato.'; }

            if ($n['blockers']) { $status = 'blocked'; }
            elseif ($n['holds']) { $status = 'bottleneck'; }
            elseif ($n['heldBy']) { $status = 'held'; }
            elseif ($n['license'] !== '') { $status = 'license'; }
            elseif ($n['softHolds'] || $n['softHeldBy'] || $n['needsFirst'] || $n['badTarget'] !== '' || $n['quarantine'] !== '' || $n['modified'] !== '' || $n['bad'] !== '') { $status = 'warn'; }
            elseif ($n['new'] !== '') { $status = 'update'; }
            elseif ($behind && $n['premium']) { $status = 'manual'; }
            elseif ($n['abandoned'] !== '') { $status = 'abandoned'; }
            elseif ($n['blind']) { $status = 'blind'; }
            else { $status = 'ok'; }
            $n['status'] = $status;
            $n['reasons'] = array_values(array_unique($reasons));
            $score = 0;
            foreach ($n['holds'] as $h) {
                $score += 30 + 4 * count($nodes[$h]['heldBy']) + (!empty($nodes[$h]['vuln']['count']) ? 40 : 0);
            }
            $score += 8 * count($n['softHolds']);
            if ($n['blockers']) { $score += 20 + ($has_vuln ? 30 : 0); }
            if ($n['license'] !== '') { $score += 20 + ($has_vuln ? 40 : 0) + ($n['new'] !== '' ? 6 : 0); }
            if ($n['abandoned'] === 'closed') { $score += 25 + ($has_vuln ? 30 : 0); }
            if ($n['modified'] === 'wporg') { $score += 18; }
            if ($n['bad'] !== '') { $score += 15; }
            if ($score && $n['premium'] && $n['new'] === '') { $score += 5; }
            $n['score'] = $score;
        }
        unset($n);
        foreach ($nodes as $id => &$n) { $n['statusLabel'] = $n['statusLabel'] ?: self::status_label($n['status']); }
        unset($n);

        $clusters = self::clusters($nodes, $edges, $ecos);
        $bottlenecks = self::bottlenecks($nodes, $edges);
        $plan = self::plan($nodes, $edges, $clusters, $report);
        $summary = self::summary($nodes, $bottlenecks);

        // Pulizia per la serializzazione.
        $out_nodes = array();
        foreach ($nodes as $n) {
            unset($n['blockers']);
            $out_nodes[] = $n;
        }
        $out_edges = array();
        foreach ($edges as $e) {
            $e['confLabel'] = self::conf_label($e['conf']);
            $out_edges[] = $e;
        }
        return array('nodes' => $out_nodes, 'edges' => $out_edges, 'clusters' => array_values($clusters),
            'bottlenecks' => $bottlenecks, 'plan' => $plan, 'summary' => $summary);
    }

    /** Header "tested/requires" rilevanti per il collegamento addon → nucleo. */
    private static function edge_headers($plugin, $hub, $hub_eco, $ecos) {
        if ($hub === 'elementor-pro') {
            return array((string) ($plugin['elementor_pro_tested'] ?? ''), '');
        }
        $key = $hub_eco[$hub] ?? '';
        if ($key === '' || empty($ecos[$key]['tested'])) { return array('', ''); }
        if ($ecos[$key]['hubs'][0] !== $hub) { return array('', ''); }
        return array((string) ($plugin[$ecos[$key]['tested']] ?? ''), (string) ($plugin[$ecos[$key]['requires']] ?? ''));
    }

    private static function worse($a, $b) {
        return (self::EDGE_RANK[$b] ?? 0) > (self::EDGE_RANK[$a] ?? 0) ? $b : $a;
    }

    private static function names($nodes, $ids) {
        $names = array();
        foreach (array_slice(array_values(array_unique((array) $ids)), 0, 6) as $id) { $names[] = $nodes[$id]['name'] ?? $id; }
        $more = count(array_unique((array) $ids)) - count($names);
        return implode(', ', $names) . ($more > 0 ? ' e altri ' . $more : '');
    }

    public static function status_label($status) {
        $labels = array('ok' => 'Allineato', 'update' => 'Aggiornabile', 'manual' => 'Aggiornamento manuale', 'warn' => 'Da coordinare',
            'held' => 'Trattenuto', 'bottleneck' => 'Collo di bottiglia', 'blocked' => 'Bloccato', 'missing' => 'Mancante',
            'license' => 'Licenza da sistemare', 'blind' => 'Versione ignota', 'abandoned' => 'Non più mantenuto');
        return $labels[$status] ?? 'Da verificare';
    }

    public static function conf_label($conf) {
        $labels = array(5 => 'Regola manuale', 4 => 'Dichiarata', 3 => 'Header di compatibilità', 2 => 'Rilevata nel codice', 1 => 'Probabile (nome)');
        return $labels[(int) $conf] ?? 'Sconosciuta';
    }

    /**
     * Origine e canale di aggiornamento.
     * Client >= 1.6 invia channel.type: wporg | vendor | external | disabled | none | unknown.
     */
    private static function origin($p, $bundled) {
        $slug = (string) ($p['slug'] ?? '');
        $channel = is_array($p['channel'] ?? null) ? $p['channel'] : array();
        $type = (string) ($channel['type'] ?? '');
        $host = (string) ($channel['host'] ?? '');
        $updaters = (array) ($p['updaters'] ?? array());
        $envato = in_array($slug, self::envato_slugs(), true);
        foreach (array('plugin_uri', 'author_uri') as $field) {
            if (preg_match('/(^|\.)(codecanyon\.net|themeforest\.net|envato\.com|envato\.market)$/', self::host_of($p[$field] ?? ''))) { $envato = true; }
        }
        $is_bundled = isset($bundled[$slug]) && ($bundled[$slug]['source'] ?? '') !== 'repo';
        $o = array('key' => 'unknown', 'label' => 'Origine non rilevata', 'hint' => '', 'premium' => false, 'envato' => $envato);
        if (in_array($slug, array('integra-sentinel-client', 'integra-sentinel-master'), true)) {
            return array_merge($o, array('key' => 'internal', 'label' => 'Componente Integra'));
        }
        $framework = array('freemius' => 'Freemius', 'edd' => 'EDD Software Licensing', 'puc' => 'Plugin Update Checker');
        $fw = array();
        foreach ($updaters as $u) { if (isset($framework[$u])) { $fw[] = $framework[$u]; } }
        $wporg_uri = preg_match('/(^|\.)wordpress\.org$/', self::host_of($p['plugin_uri'] ?? '')) === 1;
        switch ($type) {
            case 'wporg':
                return array_merge($o, array('key' => 'wporg', 'label' => 'Libreria WordPress.org'));
            case 'vendor':
                return array_merge($o, array('key' => 'vendor', 'label' => $envato ? 'Envato · updater del fornitore attivo' : 'Premium · updater del fornitore',
                    'hint' => trim(($host ? 'Canale: ' . $host : '') . ($fw ? ' · ' . implode(', ', $fw) : '')), 'premium' => true));
            case 'disabled':
                return array_merge($o, array('key' => 'custom', 'label' => 'Aggiornamenti disattivati (Update URI)', 'hint' => 'Plugin su misura o bloccato volutamente.'));
            case 'external':
                return array_merge($o, array('key' => 'nochannel', 'label' => 'Update URI senza updater attivo', 'hint' => $host ? 'Dominio dichiarato: ' . $host : '', 'premium' => true));
            case 'none':
            case 'unknown':
                if ($is_bundled) {
                    return array_merge($o, array('key' => 'bundled', 'label' => 'Incluso nel tema', 'hint' => 'Aggiornato tramite il pacchetto del tema (TGMPA).', 'premium' => true));
                }
                if ($type === 'unknown') {
                    return array_merge($o, array('label' => 'Canale non ancora verificato da WordPress', 'hint' => 'Il controllo aggiornamenti del sito non è ancora stato eseguito.'));
                }
                if ($envato) {
                    return array_merge($o, array('key' => 'nochannel', 'label' => 'Envato / CodeCanyon · aggiornamento manuale', 'hint' => 'Nessun canale automatico: scaricare dal portale Envato o attivare Envato Market.', 'premium' => true));
                }
                if ($fw) {
                    return array_merge($o, array('key' => 'nochannel', 'label' => 'Premium · licenza non attiva', 'hint' => implode(', ', $fw) . ' presente ma nessun aggiornamento registrato: verificare la licenza.', 'premium' => true));
                }
                if ($wporg_uri) {
                    return array_merge($o, array('key' => 'nochannel', 'label' => 'Rimosso o chiuso su WordPress.org', 'hint' => 'La pagina punta a wordpress.org ma il plugin non riceve aggiornamenti: possibile plugin chiuso.', 'premium' => false));
                }
                if (!empty($p['plugin_uri']) || !empty($p['author_uri'])) {
                    return array_merge($o, array('key' => 'nochannel', 'label' => 'Fuori libreria · nessun canale', 'hint' => 'Plugin commerciale o di terze parti senza aggiornamenti automatici.', 'premium' => true));
                }
                return array_merge($o, array('key' => 'custom', 'label' => 'Su misura / origine sconosciuta', 'hint' => 'Nessun autore, pagina o canale dichiarato.'));
        }
        // Client < 1.6: solo indizi.
        if (!empty($p['update_uri'])) {
            return array_merge($o, array('key' => 'vendor', 'label' => 'Canale esterno (Update URI)', 'hint' => 'Aggiornare il Client a 1.6 per il rilevamento completo.', 'premium' => true));
        }
        if ($envato) {
            return array_merge($o, array('key' => 'nochannel', 'label' => 'Probabile Envato / CodeCanyon', 'hint' => 'Aggiornare il Client a 1.6 per confermare il canale.', 'premium' => true));
        }
        return array_merge($o, array('hint' => 'Aggiornare il Client a 1.6 per distinguere WordPress.org, premium e su misura.'));
    }

    /**
     * Versione piu' recente nota da: WordPress/fornitore, catalogo (manuale o acquisti Envato), pacchetto del tema, parco siti.
     * "known" = esiste una fonte autorevole sull'ultima versione: le versioni viste sugli altri siti sono solo un limite inferiore.
     */
    private static function latest_known($p, $fleet, $catalog, $bundled) {
        $slug = (string) ($p['slug'] ?? '');
        $current = (string) ($p['version'] ?? '');
        $best = array('version' => '', 'source' => '', 'url' => '', 'known' => false);
        $candidates = array();
        if (!empty($p['update']['version'])) { $candidates[] = array((string) $p['update']['version'], 'aggiornamento segnalato dal sito', ''); $best['known'] = true; }
        if (!empty($catalog[$slug]['version'])) {
            $candidates[] = array((string) $catalog[$slug]['version'], (string) ($catalog[$slug]['source'] ?? 'catalogo Integra'), (string) ($catalog[$slug]['url'] ?? ''));
            $best['known'] = true;
        }
        if (!empty($bundled[$slug]['version']) && ($bundled[$slug]['source'] ?? '') === 'bundled') { $candidates[] = array((string) $bundled[$slug]['version'], 'pacchetto del tema', ''); $best['known'] = true; }
        $fleet_key = $slug . '|' . self::normalize_name($p['name'] ?? '');
        $is_wporg = (string) ($p['channel']['type'] ?? '') === 'wporg';
        if (!$is_wporg && !empty($fleet[$fleet_key]['version'])) {
            $candidates[] = array((string) $fleet[$fleet_key]['version'], 'visto su ' . ($fleet[$fleet_key]['site'] ?: 'altro sito'), '');
        }
        foreach ($candidates as $c) {
            if (!self::newer($c[0], $current)) { continue; }
            if ($best['version'] === '' || self::newer($c[0], $best['version'])) {
                $best = array_merge($best, array('version' => $c[0], 'source' => $c[1], 'url' => $c[2]));
            }
        }
        if ($best['url'] === '' && !empty($catalog[$slug]['url'])) { $best['url'] = (string) $catalog[$slug]['url']; }
        return $best;
    }

    private static function clusters($nodes, $edges, $ecos) {
        $clusters = array();
        foreach ($nodes as $id => $n) {
            if ($n['cluster'] === '' || $n['role'] === 'env') { continue; }
            $root = $n['cluster'];
            if (!isset($clusters[$root])) {
                $eco = $nodes[$root]['eco'] ?? '';
                $clusters[$root] = array('id' => $root, 'root' => $root, 'name' => $eco !== '' && isset($ecos[$eco]) ? $ecos[$eco]['name'] : ($nodes[$root]['name'] ?? $root),
                    'members' => array(), 'worst' => 'ok', 'verdict' => '', 'verdictTone' => 'ok', 'updates' => 0, 'issues' => 0, 'vulns' => 0);
            }
            $clusters[$root]['members'][] = $id;
            if ((self::NODE_RANK[$n['status']] ?? 0) > (self::NODE_RANK[$clusters[$root]['worst']] ?? 0)) { $clusters[$root]['worst'] = $n['status']; }
            if ($n['new'] !== '') { ++$clusters[$root]['updates']; }
            if (in_array($n['status'], array('blocked', 'bottleneck', 'held', 'warn', 'missing', 'license', 'abandoned'), true)) { ++$clusters[$root]['issues']; }
            if (!empty($n['vuln']['count'])) { $clusters[$root]['vulns'] += (int) $n['vuln']['count']; }
        }
        foreach ($clusters as $root => &$c) {
            $hub = $nodes[$root];
            $addons = count($c['members']) - 1;
            if ($hub['new'] !== '') {
                $together = 0;
                foreach ($edges as $e) { if ($e['t'] === $root && in_array($e['state'], array('coord', 'order'), true)) { ++$together; } }
                if ($hub['heldBy']) { $c['verdict'] = $hub['name'] . ' ' . $hub['new'] . ' trattenuto da ' . count($hub['heldBy']) . ' addon'; $c['verdictTone'] = 'danger'; }
                elseif ($hub['status'] === 'blocked') { $c['verdict'] = $hub['name'] . ' ' . $hub['new'] . ' bloccato da requisiti'; $c['verdictTone'] = 'danger'; }
                elseif ($hub['status'] === 'license') { $c['verdict'] = $hub['name'] . ' ' . $hub['new'] . ' non scaricabile: licenza da sistemare'; $c['verdictTone'] = 'danger'; }
                elseif ($hub['badTarget'] !== '') { $c['verdict'] = $hub['name'] . ' ' . $hub['new'] . ': versione da evitare'; $c['verdictTone'] = 'danger'; }
                elseif ($hub['quarantine'] !== '') { $c['verdict'] = $hub['name'] . ' ' . $hub['new'] . ' in quarantena fino al ' . gmdate('d/m', (int) strtotime($hub['quarantine'])); $c['verdictTone'] = 'warn'; }
                elseif ($hub['softHeldBy']) { $c['verdict'] = $hub['name'] . ' ' . $hub['new'] . ': ' . count($hub['softHeldBy']) . ' addon da verificare'; $c['verdictTone'] = 'warn'; }
                elseif ($together) { $c['verdict'] = $hub['name'] . ' ' . $hub['new'] . ' da aggiornare insieme a ' . $together . ' addon'; $c['verdictTone'] = 'brand'; }
                else { $c['verdict'] = $hub['name'] . ' ' . $hub['new'] . ' pronto: nessun addon bloccante'; $c['verdictTone'] = 'ok'; }
            } elseif ($c['issues']) {
                $c['verdict'] = $c['issues'] . ' elementi da verificare nel nucleo'; $c['verdictTone'] = 'warn';
            } else {
                $c['verdict'] = $addons . ' addon collegati, nessun blocco'; $c['verdictTone'] = 'ok';
            }
        }
        unset($c);
        uasort($clusters, function($a, $b) {
            return ((self::NODE_RANK[$b['worst']] ?? 0) <=> (self::NODE_RANK[$a['worst']] ?? 0)) ?: (count($b['members']) <=> count($a['members']));
        });
        return $clusters;
    }

    /**
     * Colli di bottiglia del sito, in ordine di impatto. Ogni elemento indica: tipo (kind), argomento su cui
     * raggruppare nel parco (subject), chi deve agire (owner), se trattiene una correzione di sicurezza e il nodo da mostrare.
     */
    private static function bottlenecks($nodes, $edges) {
        $list = array();
        $add = function($node_id, $kind, $severity, $score, $title, $detail, $action, $unlocks, $extra = array()) use (&$list, $nodes) {
            $list[] = array_merge(array(
                'id' => $kind . ':' . $node_id, 'node' => $node_id, 'kind' => $kind, 'severity' => $severity, 'score' => $score,
                'title' => $title, 'detail' => $detail, 'action' => $action, 'unlocks' => array_values((array) $unlocks),
                'subject' => $node_id, 'subjectName' => (string) ($nodes[$node_id]['name'] ?? $node_id), 'owner' => 'agenzia', 'security' => false,
            ), $extra);
        };
        foreach ($nodes as $id => $n) {
            $vuln = !empty($n['vuln']['count']);
            if ($n['role'] === 'env') {
                $blocked = (array) ($n['blocked'] ?? array());
                $add($id, 'env', 'high', 25 + 10 * count($blocked), $n['name'] . ' blocca ' . count($blocked) . ' aggiornamenti',
                    'Plugin coinvolti: ' . self::names($nodes, $blocked) . '.',
                    $id === 'env:php' ? 'Pianificare con l\'hosting il passaggio a PHP ≥ ' . ($n['need'] ?? '') . ' (testare prima in staging).' : 'Aggiornare WordPress' . ($n['new'] !== '' ? ' alla ' . $n['new'] : '') . ', poi i plugin.',
                    $blocked, array('subjectName' => $id === 'env:php' ? 'PHP del server' : 'WordPress', 'owner' => $id === 'env:php' ? 'hosting' : 'agenzia'));
                continue;
            }
            if ($n['role'] === 'missing') {
                $add($id, 'missing', 'high', 40, 'Dipendenza mancante: ' . $id, implode(' ', $n['reasons']),
                    'Installare e attivare ' . $id . ' oppure disattivare i plugin che lo richiedono.', array());
                continue;
            }
            if ($n['holds']) {
                $security = false; $targets = array();
                foreach ($n['holds'] as $h) {
                    $targets[] = $nodes[$h]['name'] . ' ' . $nodes[$h]['ver'] . ' → ' . $nodes[$h]['new'];
                    if (!empty($nodes[$h]['vuln']['count'])) { $security = true; }
                }
                $add($id, $security ? 'security' : 'hold', $security ? 'critical' : 'high', $n['score'], $n['name'] . ' trattiene ' . self::names($nodes, $n['holds']),
                    'Blocca: ' . implode('; ', $targets) . '.' . ($security ? ' L\'aggiornamento trattenuto corregge vulnerabilità note.' : '') . ($n['premium'] ? ' Plugin premium: ' . $n['originLabel'] . '.' : ''),
                    $n['premium'] ? 'Verificare con il fornitore una versione compatibile, testare in staging o valutare un\'alternativa.' : 'Verificare il changelog/supporto dell\'addon, testare in staging o valutare la sostituzione.',
                    $n['holds'], array('owner' => $n['premium'] ? 'fornitore' : 'agenzia', 'security' => $security));
            } elseif ($n['softHolds'] && $n['active']) {
                $add($id, 'unknown', 'medium', $n['score'], $n['name'] . ': compatibilità non dichiarata', 'Con ' . self::names($nodes, $n['softHolds']) . ' in aggiornamento e nessun aggiornamento dell\'addon.',
                    'Testare in staging dopo l\'aggiornamento del nucleo.', $n['softHolds']);
            }
            if ($n['status'] === 'blocked' && !$n['holds']) {
                $add($id, 'blocked', $vuln ? 'critical' : 'high', $n['score'], $n['name'] . ' non aggiornabile', implode(' ', array_slice($n['reasons'], 0, 3)),
                    'Risolvere prima i requisiti indicati.', array($id), array('security' => $vuln));
            }
            if ($n['license'] !== '') {
                // owner: "agenzia" = licenza dell'agenzia disponibile, "cliente" = licenza del cliente, "" = non registrata.
                $owner = $n['owner'] !== '' ? $n['owner'] : 'agenzia';
                $titles = array('missing' => 'chiave di licenza assente', 'expired' => 'licenza scaduta', 'invalid' => 'licenza non valida', 'nopkg' => 'aggiornamento non scaricabile');
                $action = $n['owner'] === 'cliente' ? 'Chiedere al cliente di rinnovare o acquistare la licenza, poi aggiornare.'
                    : ($n['owner'] === 'agenzia' ? 'Attivare sul sito la licenza dell\'agenzia (registro licenze), poi aggiornare.'
                    : 'Licenza non registrata: verificare chi la possiede, attivarla sul sito oppure farla rinnovare al cliente.');
                $add($id, 'license', $vuln ? 'critical' : ($n['new'] !== '' ? 'high' : 'medium'), $n['score'], $n['name'] . ': ' . ($titles[$n['license']] ?? 'licenza da verificare'),
                    implode(' ', array_slice($n['reasons'], 0, 2)), $action, array($id), array('owner' => $owner, 'security' => $vuln));
            }
            $bundled = $n['origin'] === 'bundled';
            if ($n['status'] === 'manual') {
                $add($id, $bundled ? 'bundled' : 'manual', $vuln ? 'high' : 'low', 6 + ($vuln ? 30 : 0),
                    $n['name'] . ': ' . $n['ver'] . ' → ' . $n['latest'] . ($bundled ? ' nel pacchetto del tema' : ' disponibile fuori da WordPress'),
                    'Fonte: ' . $n['latestSrc'] . '. ' . $n['originLabel'] . '.' . ($n['bundleTheme'] !== '' ? ' Incluso nel tema ' . $n['bundleTheme'] . '.' : ''),
                    $bundled ? 'Aggiornare il tema (serve la sua licenza) oppure installare il pacchetto aggiornato incluso nel tema.' : 'Scaricare il pacchetto dal fornitore (licenza dell\'agenzia o del cliente) e aggiornare manualmente.',
                    array($id), array('security' => $vuln));
            } elseif ($n['blind'] && in_array($n['status'], array('blind', 'warn', 'abandoned'), true)) {
                $add($id, $bundled ? 'bundled' : 'blind', $vuln ? 'high' : 'low', 4 + ($vuln ? 30 : 0),
                    $n['name'] . ': ' . ($bundled ? 'incluso nel tema, versione più recente sconosciuta' : 'ultima versione sconosciuta'),
                    $n['originLabel'] . '. Nessun canale di aggiornamento e nessuna versione di riferimento nel catalogo, negli acquisti Envato o negli altri siti.',
                    $bundled ? 'Verificare se il tema' . ($n['bundleTheme'] !== '' ? ' ' . $n['bundleTheme'] : '') . ' ha una versione più recente con il plugin aggiornato.' : 'Verificare la versione sul sito del fornitore e inserirla nel catalogo premium (Impostazioni).',
                    array($id), array('security' => $vuln));
            }
            if ($n['abandoned'] !== '') {
                $closed = $n['abandoned'] === 'closed';
                $add($id, 'abandoned', $closed ? ($vuln ? 'critical' : 'high') : ($vuln ? 'high' : 'medium'), $closed ? 30 : 12,
                    $n['name'] . ($closed ? ': chiuso su WordPress.org' : ': non più aggiornato'), $n['abandonedNote'],
                    $n['active'] ? 'Pianificare la sostituzione con un plugin mantenuto.' : 'Plugin inattivo: rimuoverlo.', array($id), array('security' => $vuln));
            }
            if ($n['modified'] !== '') {
                $official = $n['modified'] === 'wporg';
                $add($id, 'modified', $official ? 'high' : 'medium', $official ? 20 : 10,
                    $n['name'] . ($official ? ': file principale diverso dall\'originale' : ': file diverso dagli altri siti'), $n['modifiedNote'],
                    'Confrontare con il pacchetto originale: se sono modifiche dell\'agenzia spostarle in un plugin o mu-plugin separato, altrimenti trattarle come possibile infezione.',
                    array($id));
            }
            if ($n['bad'] !== '' || $n['badTarget'] !== '') {
                $installed = $n['bad'] !== '';
                $add($id, 'badversion', $installed ? 'high' : 'medium', $installed ? 16 : 8,
                    $n['name'] . ($installed ? ': versione installata da evitare' : ': non aggiornare a ' . $n['new']), $installed ? $n['bad'] : $n['badTarget'],
                    $installed ? 'Passare a una versione non segnalata (correttiva o precedente) appena possibile.' : 'Attendere la versione correttiva successiva.',
                    array($id));
            }
        }
        $rank = array('critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1);
        usort($list, function($a, $b) use ($rank) {
            return ($rank[$b['severity']] <=> $rank[$a['severity']]) ?: ($b['score'] <=> $a['score']);
        });
        return array_slice($list, 0, 60);
    }

    /** Piano in fasi: ambiente → aggiornamenti liberi → nuclei → manuali → licenze → quarantena → da non aggiornare → da sostituire. */
    private static function plan($nodes, $edges, $clusters, $report) {
        $phases = array();
        $item = function($n, $text = '', $tone = '') {
            return array('id' => $n['id'], 'name' => $n['name'], 'from' => $n['ver'], 'to' => $n['new'] !== '' ? $n['new'] : $n['latest'],
                'text' => $text, 'tone' => $tone ?: $n['status'], 'vuln' => !empty($n['vuln']['count']));
        };
        $waiting = function($n) {
            return in_array($n['status'], array('blocked', 'license'), true) || $n['quarantine'] !== '' || $n['badTarget'] !== '';
        };
        $env = array();
        foreach (array('env:wp', 'env:php') as $env_id) {
            if (!isset($nodes[$env_id])) { continue; }
            $n = $nodes[$env_id];
            $env[] = array('id' => $env_id, 'name' => $n['name'], 'from' => $n['ver'], 'to' => $env_id === 'env:wp' ? $n['new'] : '≥ ' . ($n['need'] ?? ''),
                'text' => 'Sblocca ' . count($n['blocked'] ?? array()) . ' aggiornamenti.', 'tone' => $env_id === 'env:php' ? 'blocked' : 'warn', 'vuln' => false);
        }
        if ($env) { $phases[] = array('key' => 'env', 'title' => 'Prerequisiti di ambiente', 'hint' => 'Da risolvere prima degli aggiornamenti che li richiedono.', 'items' => $env); }

        $free = array();
        foreach ($nodes as $n) {
            if ($n['role'] === 'solo' && $n['status'] === 'update') { $free[] = $item($n, $n['vuln'] ? 'Corregge vulnerabilità note: priorità.' : ''); }
        }
        usort($free, function($a, $b) { return ((int) $b['vuln'] <=> (int) $a['vuln']) ?: strcasecmp($a['name'], $b['name']); });
        if ($free) { $phases[] = array('key' => 'free', 'title' => 'Aggiornamenti indipendenti', 'hint' => 'Nessuna dipendenza rilevata: si possono aggiornare in un unico lotto.', 'items' => $free); }

        foreach ($clusters as $c) {
            $root = $nodes[$c['root']];
            $steps = array();
            $hub_free = $root['new'] !== '' && !$root['heldBy'] && !$waiting($root);
            $early = function($m) use ($nodes, $c, $waiting) {
                return $nodes[$m]['new'] !== '' && !$waiting($nodes[$m]) && !in_array($c['root'], $nodes[$m]['needsFirst'], true);
            };
            if ($root['new'] !== '' && !$hub_free) {
                $addon_updates = array();
                foreach ($c['members'] as $m) {
                    if ($m !== $c['root'] && $early($m)) { $addon_updates[] = $m; }
                }
                foreach ($addon_updates as $m) {
                    $steps[] = $item($nodes[$m], $root['heldBy'] ? 'Aggiornare prima l\'addon: la nuova versione potrebbe dichiarare la compatibilità.' : 'Aggiornabile ora, indipendentemente dal nucleo.', 'update');
                }
                $steps[] = $item($root, $root['heldBy'] ? 'In attesa: trattenuto da ' . self::names($nodes, $root['heldBy']) . '.' : implode(' ', array_slice($root['reasons'], 0, 2)), $root['status'] === 'license' ? 'license' : 'held');
            } elseif ($hub_free) {
                $steps[] = $item($root, 'Aggiornare per primo il nucleo' . ($root['vuln'] ? ' (corregge vulnerabilità).' : '.'));
            }
            $ordered = $c['members'];
            usort($ordered, function($a, $b) use ($nodes) { return ($nodes[$a]['depth'] <=> $nodes[$b]['depth']) ?: strcasecmp($nodes[$a]['name'], $nodes[$b]['name']); });
            foreach ($ordered as $m) {
                if ($m === $c['root']) { continue; }
                $n = $nodes[$m];
                if ($root['new'] !== '' && !$hub_free && $early($m)) { continue; } // gia' in testa
                if ($waiting($n)) { $steps[] = $item($n, implode(' ', array_slice($n['reasons'], 0, 2))); continue; }
                if ($n['status'] === 'bottleneck') { $steps[] = $item($n, 'Collo di bottiglia: ' . implode(' ', array_slice($n['reasons'], 0, 1))); continue; }
                if ($n['new'] !== '') {
                    $steps[] = $item($n, $n['needsFirst'] ? 'Dopo ' . self::names($nodes, $n['needsFirst']) . '.' : ($hub_free ? 'Subito dopo ' . $root['name'] . ', nella stessa finestra.' : ''));
                } elseif ($n['status'] === 'warn' && $n['active']) {
                    $steps[] = $item($n, 'Nessun aggiornamento: verificare il funzionamento dopo il nucleo.', 'warn');
                }
            }
            if ($steps) {
                $phases[] = array('key' => 'cluster:' . $c['id'], 'title' => 'Nucleo ' . $c['name'], 'hint' => $c['verdict'], 'tone' => $c['verdictTone'], 'items' => $steps);
            }
        }

        $manual = array(); $license = array(); $quarantine = array(); $avoid = array(); $replace = array(); $check = array();
        foreach ($nodes as $n) {
            if (in_array($n['role'], array('env', 'missing'), true)) { continue; }
            $solo = $n['role'] === 'solo';
            if ($n['status'] === 'manual') { $manual[] = $item($n, 'Fonte: ' . $n['latestSrc'] . '. ' . $n['originLabel'] . '.'); }
            if ($solo && $n['status'] === 'license' && $n['new'] !== '') { $license[] = $item($n, implode(' ', array_slice($n['reasons'], 0, 1)), 'license'); }
            if ($solo && $n['quarantine'] !== '' && $n['status'] !== 'license') { $quarantine[] = $item($n, 'Aggiornabile dal ' . gmdate('d/m', (int) strtotime($n['quarantine'])) . ', salvo problemi segnalati.', 'warn'); }
            if ($solo && $n['badTarget'] !== '') { $avoid[] = $item($n, $n['badTarget'], 'blocked'); }
            if ($solo && $n['modified'] !== '' && $n['new'] !== '' && $n['status'] === 'warn' && $n['quarantine'] === '' && $n['badTarget'] === '') { $check[] = $item($n, 'Verificare le modifiche ai file prima di aggiornare: verrebbero sovrascritte.', 'warn'); }
            if ($n['abandoned'] !== '') { $replace[] = $item($n, $n['abandonedNote'], 'warn'); }
        }
        if ($manual) { $phases[] = array('key' => 'manual', 'title' => 'Aggiornamenti manuali (premium)', 'hint' => 'Pacchetti da scaricare dal fornitore: WordPress non li propone automaticamente.', 'items' => $manual); }
        if ($check) { $phases[] = array('key' => 'check', 'title' => 'Da verificare prima di aggiornare', 'hint' => 'File diversi dall’originale: un aggiornamento cancellerebbe le modifiche.', 'tone' => 'warn', 'items' => $check); }
        if ($license) { $phases[] = array('key' => 'license', 'title' => 'Licenze da sistemare', 'hint' => 'Aggiornamenti disponibili ma non scaricabili finché la licenza non è attiva.', 'tone' => 'danger', 'items' => $license); }
        if ($quarantine) { $phases[] = array('key' => 'quarantine', 'title' => 'In quarantena', 'hint' => 'Nuove versioni appena rilasciate: si aspetta qualche giorno, salvo patch di sicurezza.', 'tone' => 'warn', 'items' => $quarantine); }
        if ($avoid) { $phases[] = array('key' => 'avoid', 'title' => 'Da non aggiornare', 'hint' => 'Versioni segnalate dall’agenzia: attendere la correttiva.', 'tone' => 'danger', 'items' => $avoid); }
        if ($replace) { $phases[] = array('key' => 'replace', 'title' => 'Da sostituire', 'hint' => 'Plugin chiusi o non più mantenuti: non riceveranno correzioni.', 'tone' => 'warn', 'items' => $replace); }

        return $phases;
    }

    private static function summary($nodes, $bottlenecks) {
        $s = array('plugins' => 0, 'updates' => 0, 'ready' => 0, 'blocked' => 0, 'held' => 0, 'bottlenecks' => 0, 'critical' => 0,
            'origins' => array('wporg' => 0, 'vendor' => 0, 'nochannel' => 0, 'bundled' => 0, 'custom' => 0, 'unknown' => 0, 'internal' => 0),
            'premium' => 0, 'envato' => 0, 'manual' => 0, 'clusters' => 0,
            'license' => 0, 'blind' => 0, 'abandoned' => 0, 'modified' => 0, 'quarantined' => 0, 'bad' => 0, 'blockedUpdates' => 0, 'auto' => 0, 'security' => 0);
        $roots = array();
        foreach ($nodes as $n) {
            if (in_array($n['role'], array('env', 'missing'), true)) { continue; }
            ++$s['plugins'];
            if (isset($s['origins'][$n['origin']])) { ++$s['origins'][$n['origin']]; }
            if ($n['premium']) { ++$s['premium']; }
            if ($n['envato']) { ++$s['envato']; }
            if ($n['new'] !== '') {
                ++$s['updates'];
                if ($n['status'] === 'update') { ++$s['ready']; }
                if (in_array($n['status'], array('held', 'blocked', 'license'), true)) { ++$s['blockedUpdates']; }
            }
            if ($n['status'] === 'blocked') { ++$s['blocked']; }
            if ($n['status'] === 'held') { ++$s['held']; }
            if ($n['status'] === 'manual') { ++$s['manual']; }
            if ($n['license'] !== '') { ++$s['license']; }
            if ($n['blind']) { ++$s['blind']; }
            if ($n['abandoned'] !== '') { ++$s['abandoned']; }
            if ($n['modified'] !== '') { ++$s['modified']; }
            if ($n['quarantine'] !== '') { ++$s['quarantined']; }
            if ($n['bad'] !== '' || $n['badTarget'] !== '') { ++$s['bad']; }
            if ($n['auto']) { ++$s['auto']; }
            if ($n['role'] === 'hub') { $roots[$n['id']] = true; }
        }
        foreach ($bottlenecks as $b) {
            ++$s['bottlenecks'];
            if ($b['severity'] === 'critical') { ++$s['critical']; }
            if (!empty($b['security'])) { ++$s['security']; }
        }
        $s['clusters'] = count($roots);
        return $s;
    }
}
