<?php
/**
 * Integra Sentinel — registro licenze dell'agenzia: chi possiede ogni licenza premium, quanti siti copre,
 * quando scade e quanto costa. Serve a dire, per ogni licenza mancante, se basta attivare quella
 * dell'agenzia o se deve intervenire il cliente. Le chiavi di licenza NON vengono mai salvate.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Licenses {

    const OPTION = 'integra_sentinel_licenses';

    public static function types() {
        return array(
            'agency_unlimited' => 'Agenzia · siti illimitati',
            'agency_seats' => 'Agenzia · numero di siti',
            'agency_lifetime' => 'Agenzia · a vita',
            'client' => 'Del cliente',
        );
    }

    public static function all() {
        $list = get_option(self::OPTION, array());
        return is_array($list) ? $list : array();
    }

    public static function get($id) {
        $all = self::all();
        return $all[$id] ?? null;
    }

    public static function save($data) {
        $types = self::types();
        $all = self::all();
        $id = preg_match('/^[a-f0-9]{12}$/', (string) ($data['id'] ?? '')) && isset($all[$data['id']]) ? $data['id'] : substr(md5(uniqid('lic', true)), 0, 12);
        $slugs = array();
        foreach (preg_split('/[\s,;]+/', (string) ($data['slugs'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $slug) {
            $slug = sanitize_title($slug);
            if ($slug !== '') { $slugs[$slug] = true; }
        }
        $product = substr(sanitize_text_field((string) ($data['product'] ?? '')), 0, 120);
        if ($product === '' || !$slugs) { return ''; }
        $type = isset($types[$data['type'] ?? '']) ? $data['type'] : 'agency_unlimited';
        $sites = array();
        foreach ((array) ($data['sites'] ?? array()) as $site_id) {
            if (preg_match('/^[a-f0-9-]{36}$/i', (string) $site_id)) { $sites[] = strtolower((string) $site_id); }
        }
        $expires = (string) ($data['expires'] ?? '');
        $old_expires = isset($all[$id]) ? (string) ($all[$id]['expires'] ?? '') : null;
        $all[$id] = array(
            'id' => $id,
            'product' => $product,
            'slugs' => array_keys($slugs),
            'type' => $type,
            'seats' => $type === 'agency_seats' ? max(1, min(100000, absint($data['seats'] ?? 1))) : 0,
            'expires' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) && $type !== 'agency_lifetime' ? $expires : '',
            'cost' => round(max(0, min(1000000, (float) str_replace(',', '.', (string) ($data['cost'] ?? 0)))), 2),
            'payer' => ($data['payer'] ?? '') === 'cliente' || $type === 'client' ? 'cliente' : 'agenzia',
            'sites' => array_values(array_unique($sites)),
            'vendor_url' => Integra_Sentinel_Master::safe_link((string) ($data['vendor_url'] ?? '')),
            'notes' => substr(sanitize_textarea_field((string) ($data['notes'] ?? '')), 0, 800),
            'reminded' => (array) ($all[$id]['reminded'] ?? array()),
        );
        if ($old_expires !== null && $old_expires !== $all[$id]['expires']) { $all[$id]['reminded'] = array(); }
        update_option(self::OPTION, $all, false);
        return $id;
    }

    public static function delete($id) {
        $all = self::all();
        if (!isset($all[$id])) { return false; }
        unset($all[$id]);
        return update_option(self::OPTION, $all, false);
    }

    public static function mark_reminded($id, $threshold) {
        $all = self::all();
        if (!isset($all[$id])) { return; }
        $all[$id]['reminded'][] = (string) $threshold;
        $all[$id]['reminded'] = array_values(array_unique($all[$id]['reminded']));
        update_option(self::OPTION, $all, false);
    }

    public static function for_slug($slug) {
        $out = array();
        foreach (self::all() as $entry) {
            if (in_array($slug, (array) ($entry['slugs'] ?? array()), true)) { $out[] = $entry; }
        }
        return $out;
    }

    /**
     * Chi deve agire per la licenza di uno slug su un sito: "cliente" se registrata come del cliente,
     * "agenzia" se esiste una licenza dell'agenzia (da attivare, rinnovare o ampliare), "" se nessuna e' registrata.
     */
    public static function owner_for($slug, $site_id) {
        $entries = self::for_slug($slug);
        if (!$entries) { return ''; }
        $agency = false;
        foreach ($entries as $e) {
            if (($e['type'] ?? '') === 'client') {
                if (!$e['sites'] || in_array($site_id, (array) $e['sites'], true)) { return 'cliente'; }
                continue;
            }
            $agency = true;
        }
        return $agency ? 'agenzia' : 'cliente';
    }

    public static function status($entry) {
        if (($entry['type'] ?? '') === 'agency_lifetime' || empty($entry['expires'])) { return 'perpetual'; }
        $days = (int) floor((strtotime($entry['expires'] . ' 23:59:59') - current_time('timestamp')) / DAY_IN_SECONDS);
        if ($days < 0) { return 'expired'; }
        return $days <= 30 ? 'soon' : 'ok';
    }

    public static function days_left($entry) {
        if (empty($entry['expires'])) { return null; }
        return (int) floor((strtotime($entry['expires'] . ' 23:59:59') - current_time('timestamp')) / DAY_IN_SECONDS);
    }

    /** Siti del parco che usano i prodotti di ciascuna licenza. */
    public static function usage($sites) {
        $out = array();
        foreach (self::all() as $id => $entry) {
            $used = array();
            foreach ((array) $sites as $site_id => $site) {
                foreach ((array) ($site['report']['plugins'] ?? array()) as $p) {
                    if (in_array((string) ($p['slug'] ?? ''), (array) $entry['slugs'], true)) { $used[$site_id] = (string) ($site['label'] ?? $site_id); break; }
                }
                if (!isset($used[$site_id])) {
                    foreach ((array) ($site['report']['themes'] ?? array()) as $t) {
                        if (!empty($t['role']) && in_array((string) ($t['slug'] ?? ''), (array) $entry['slugs'], true)) { $used[$site_id] = (string) ($site['label'] ?? $site_id); break; }
                    }
                }
            }
            $out[$id] = array('sites' => $used, 'over' => ($entry['type'] ?? '') === 'agency_seats' && count($used) > (int) $entry['seats']);
        }
        return $out;
    }

    public static function annual_cost() {
        $out = array('agenzia' => 0.0, 'cliente' => 0.0);
        foreach (self::all() as $entry) {
            if (($entry['type'] ?? '') === 'agency_lifetime') { continue; }
            $out[$entry['payer'] === 'cliente' ? 'cliente' : 'agenzia'] += (float) ($entry['cost'] ?? 0);
        }
        return $out;
    }

    /** Licenze in scadenza entro N giorni (o scadute da meno di 30). */
    public static function expiring($days = 30) {
        $out = array();
        foreach (self::all() as $entry) {
            $left = self::days_left($entry);
            if ($left !== null && ($entry['type'] ?? '') !== 'agency_lifetime' && $left <= $days && $left >= -30) { $out[] = $entry + array('days' => $left); }
        }
        usort($out, function($a, $b) { return $a['days'] <=> $b['days']; });
        return $out;
    }
}
