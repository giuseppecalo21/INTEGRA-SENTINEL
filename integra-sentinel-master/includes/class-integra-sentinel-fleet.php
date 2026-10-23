<?php
/**
 * Integra Sentinel — vista d'insieme del parco: colli di bottiglia raggruppati per causa e per prodotto
 * ("ACF PRO: licenza scaduta su 6 siti" = un'azione), indicatori di aggiornabilita' e vista per plugin.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Fleet {

    private static $memo = null;

    /** Tipi di collo di bottiglia, nell'ordine in cui si mostrano. */
    public static function kinds() {
        return array(
            'security' => array('label' => 'Patch di sicurezza bloccate', 'hint' => 'Un addon impedisce di aggiornare un plugin principale che corregge vulnerabilità note.'),
            'license' => array('label' => 'Licenze mancanti o scadute', 'hint' => 'Aggiornamenti visibili ma non scaricabili, oppure chiavi di licenza non inserite.'),
            'hold' => array('label' => 'Addon che trattengono un plugin principale', 'hint' => 'Testati solo con versioni precedenti del nucleo e senza aggiornamenti.'),
            'blocked' => array('label' => 'Requisiti non soddisfatti', 'hint' => 'La nuova versione richiede altro prima di poter essere installata.'),
            'env' => array('label' => 'PHP o WordPress troppo vecchi per gli aggiornamenti', 'hint' => 'Aggiornamenti che richiedono una versione più recente di PHP o del core.'),
            'server' => array('label' => 'Server che impedisce gli aggiornamenti', 'hint' => 'Permessi, spazio su disco, memoria, credenziali FTP o WP-Cron.'),
            'platform' => array('label' => 'Piattaforma fuori supporto', 'hint' => 'PHP, database o WordPress oltre la fine del supporto.'),
            'missing' => array('label' => 'Dipendenze mancanti', 'hint' => 'Plugin richiesti da altri ma non installati.'),
            'abandoned' => array('label' => 'Plugin chiusi o abbandonati', 'hint' => 'Non riceveranno più correzioni: vanno sostituiti.'),
            'modified' => array('label' => 'File diversi dall’originale', 'hint' => 'Modifiche locali (perse all’aggiornamento) oppure possibili infezioni.'),
            'badversion' => array('label' => 'Versioni da evitare', 'hint' => 'Versioni segnalate dall’agenzia in Impostazioni → Politiche.'),
            'manual' => array('label' => 'Premium da aggiornare a mano', 'hint' => 'Esiste una versione più recente ma WordPress non la propone: va scaricata dal fornitore.'),
            'bundled' => array('label' => 'Plugin inclusi nel tema', 'hint' => 'Si aggiornano con il pacchetto del tema (TGMPA).'),
            'theme' => array('label' => 'Temi', 'hint' => 'Child theme assente o personalizzazioni a rischio.'),
            'postupdate' => array('label' => 'Lavori rimasti dopo un aggiornamento', 'hint' => 'Database da aggiornare o template WooCommerce obsoleti.'),
            'backup' => array('label' => 'Backup non verificato', 'hint' => 'Prima di aggiornare serve un backup recente e riuscito.'),
            'blind' => array('label' => 'Punti ciechi', 'hint' => 'Premium senza canale e senza versione di riferimento: non si sa se sono aggiornati.'),
            'unknown' => array('label' => 'Compatibilità non dichiarata', 'hint' => 'Da provare in staging dopo l’aggiornamento del plugin principale.'),
            'stack' => array('label' => 'Pulizia e standard', 'hint' => 'Doppioni, plugin inattivi, plugin fuori dallo stack dell’agenzia.'),
        );
    }

    public static function kind_label($kind) {
        $kinds = self::kinds();
        return $kinds[$kind]['label'] ?? 'Da verificare';
    }

    public static function owners() {
        return array('agenzia' => 'Agenzia', 'cliente' => 'Cliente', 'hosting' => 'Hosting', 'fornitore' => 'Fornitore');
    }

    public static function severity_rank($severity) {
        return array('critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1)[$severity] ?? 0;
    }

    public static function severity_label($severity) {
        return array('critical' => 'Critico', 'high' => 'Alto', 'medium' => 'Medio', 'low' => 'Basso')[$severity] ?? 'Da verificare';
    }

    public static function severity_tone($severity) {
        return array('critical' => 'critical', 'high' => 'high', 'medium' => 'warn', 'low' => 'premium')[$severity] ?? 'muted';
    }

    public static function reset() { self::$memo = null; }

    /** Chiave del gruppo nel parco (stessa causa, stesso prodotto; per le licenze anche stesso responsabile). */
    public static function group_key($b) {
        return substr(md5($b['kind'] . '|' . $b['subject'] . ($b['kind'] === 'license' ? '|' . $b['owner'] : '')), 0, 10);
    }

    /**
     * @return array kinds (tipo => gruppi ordinati), groups (n), kpi, kpi_sites, waived, per_site, owners
     */
    public static function aggregate() {
        if (self::$memo !== null) { return self::$memo; }
        $sites = Integra_Sentinel_Master::sites();
        $groups = array(); $waived = array(); $per_site = array();
        $keys = array('ready', 'blocked', 'license', 'manual', 'blind', 'security', 'bottlenecks', 'critical');
        $kpi = array_fill_keys($keys, 0) + array('minutes' => 0, 'analyzed' => 0);
        $kpi_sites = array_fill_keys($keys, 0);
        foreach ($sites as $id => $site) {
            $a = Integra_Sentinel_Analysis::site($site);
            if (!$a) { continue; }
            ++$kpi['analyzed'];
            $per_site[$id] = $a;
            foreach ($keys as $k) {
                $v = (int) ($a['summary'][$k] ?? 0);
                $kpi[$k] += $v;
                if ($v) { ++$kpi_sites[$k]; }
            }
            $kpi['minutes'] += (int) $a['summary']['minutes'];
            foreach ($a['bottlenecks'] as $b) {
                $gk = $b['kind'] . '|' . $b['subject'] . ($b['kind'] === 'license' ? '|' . $b['owner'] : '');
                if (!isset($groups[$gk])) {
                    $groups[$gk] = array('key' => self::group_key($b), 'kind' => $b['kind'], 'subject' => $b['subject'], 'name' => (string) $b['subjectName'],
                        'severity' => 'low', 'owner' => (string) $b['owner'], 'security' => false, 'score' => 0, 'sites' => array(), 'items' => array(),
                        'unlocks' => 0, 'minutes' => 0, 'title' => (string) $b['title'], 'detail' => (string) $b['detail'], 'action' => (string) $b['action']);
                }
                $g = &$groups[$gk];
                if (self::severity_rank($b['severity']) > self::severity_rank($g['severity'])) { $g['severity'] = $b['severity']; }
                $g['security'] = $g['security'] || !empty($b['security']);
                $g['score'] = max($g['score'], (int) $b['score']);
                $g['sites'][$id] = (string) ($site['label'] ?? $id);
                $g['items'][$id] = $b;
                $g['unlocks'] += count((array) $b['unlocks']);
                $g['minutes'] += (int) $b['minutes'];
                unset($g);
            }
            foreach ($a['waived'] as $b) { $waived[] = $b + array('site_id' => $id, 'site_label' => (string) ($site['label'] ?? $id)); }
        }
        $by_kind = array();
        foreach ($groups as $g) { $by_kind[$g['kind']][] = $g; }
        foreach ($by_kind as &$list) {
            usort($list, function($a, $b) {
                return (self::severity_rank($b['severity']) <=> self::severity_rank($a['severity'])) ?: ((int) $b['security'] <=> (int) $a['security'])
                    ?: (count($b['sites']) <=> count($a['sites'])) ?: ($b['score'] <=> $a['score']) ?: strcasecmp($a['name'], $b['name']);
            });
        }
        unset($list);
        $ordered = array();
        foreach (array_keys(self::kinds()) as $kind) { if (!empty($by_kind[$kind])) { $ordered[$kind] = $by_kind[$kind]; } }
        $owners = array();
        foreach ($groups as $g) { $owners[$g['owner']] = ($owners[$g['owner']] ?? 0) + 1; }
        return self::$memo = array('kinds' => $ordered, 'groups' => count($groups), 'kpi' => $kpi, 'kpi_sites' => $kpi_sites,
            'waived' => $waived, 'per_site' => $per_site, 'owners' => $owners);
    }

    /** Gruppi in ordine di urgenza, indipendentemente dal tipo (per il riepilogo e l'email). */
    public static function top_groups($fleet, $limit = 6) {
        $all = array();
        foreach ($fleet['kinds'] as $list) { foreach ($list as $g) { $all[] = $g; } }
        usort($all, function($a, $b) {
            return (self::severity_rank($b['severity']) <=> self::severity_rank($a['severity'])) ?: ((int) $b['security'] <=> (int) $a['security'])
                ?: (count($b['sites']) <=> count($a['sites'])) ?: ($b['score'] <=> $a['score']);
        });
        return array_slice($all, 0, $limit);
    }

    /**
     * Vista per prodotto: ogni plugin/tema del parco con versioni, stato e ondata di ogni sito.
     * @return array slug|type => name, type, sites (id => dati), latest, versions
     */
    public static function products($fleet) {
        $sites = Integra_Sentinel_Master::sites();
        $out = array();
        foreach ($fleet['per_site'] as $id => $a) {
            $site = $sites[$id] ?? array();
            $ring = (int) ($site['meta']['ring'] ?? 2);
            foreach ($a['graph']['nodes'] as $n) {
                if (in_array($n['role'], array('env', 'missing'), true) || $n['origin'] === 'internal') { continue; }
                $key = 'plugin:' . $n['id'];
                if (!isset($out[$key])) { $out[$key] = array('type' => 'plugin', 'slug' => $n['id'], 'name' => $n['name'], 'sites' => array(), 'latest' => '', 'premium' => false, 'vuln' => 0); }
                $p = &$out[$key];
                $p['premium'] = $p['premium'] || !empty($n['premium']);
                $target = $n['new'] !== '' ? $n['new'] : $n['latest'];
                foreach (array($n['ver'], $target) as $v) {
                    if (Integra_Sentinel_Dependencies::valid_version((string) $v) && ($p['latest'] === '' || version_compare(ltrim($v, 'v'), ltrim($p['latest'], 'v'), '>'))) { $p['latest'] = (string) $v; }
                }
                if (!empty($n['vuln']['count'])) { ++$p['vuln']; }
                $p['sites'][$id] = array('label' => (string) ($site['label'] ?? $id), 'ring' => $ring, 'ver' => $n['ver'], 'new' => $n['new'], 'latest' => $n['latest'],
                    'status' => $n['status'], 'statusLabel' => $n['statusLabel'], 'active' => $n['active'], 'auto' => !empty($n['auto']),
                    'license' => $n['license'], 'vuln' => $n['vuln'], 'origin' => $n['originLabel'], 'quarantine' => $n['quarantine'], 'reasons' => $n['reasons']);
                unset($p);
            }
            foreach ($a['themes']['themes'] as $t) {
                $key = 'theme:' . $t['slug'];
                if (!isset($out[$key])) { $out[$key] = array('type' => 'theme', 'slug' => $t['slug'], 'name' => $t['name'], 'sites' => array(), 'latest' => '', 'premium' => false, 'vuln' => 0); }
                $p = &$out[$key];
                $p['premium'] = $p['premium'] || !empty($t['premium']);
                $target = $t['update'] !== '' ? $t['update'] : $t['latest'];
                foreach (array($t['version'], $target) as $v) {
                    if (Integra_Sentinel_Dependencies::valid_version((string) $v) && ($p['latest'] === '' || version_compare(ltrim($v, 'v'), ltrim($p['latest'], 'v'), '>'))) { $p['latest'] = (string) $v; }
                }
                if (!empty($t['vuln']['count'])) { ++$p['vuln']; }
                $p['sites'][$id] = array('label' => (string) ($site['label'] ?? $id), 'ring' => $ring, 'ver' => $t['version'], 'new' => $t['update'], 'latest' => $t['latest'],
                    'status' => $t['status'], 'statusLabel' => Integra_Sentinel_Dependencies::status_label($t['status']), 'active' => $t['role'] !== '', 'auto' => false,
                    'license' => $t['license'], 'vuln' => $t['vuln'], 'origin' => $t['originLabel'], 'quarantine' => '', 'reasons' => $t['reasons']);
                unset($p);
            }
        }
        uasort($out, function($a, $b) { return (count($b['sites']) <=> count($a['sites'])) ?: strcasecmp($a['name'], $b['name']); });
        return $out;
    }

    /**
     * Rollout a ondate per un prodotto: l'ondata piu' bassa non ancora allineata e' quella da aggiornare ora.
     * Ondate: 0 = sito dell'agenzia/test, 1 = siti vetrina, 2 = siti importanti, 3 = e-commerce e critici.
     */
    public static function rollout($product) {
        $latest = (string) $product['latest'];
        $rings = array();
        foreach ($product['sites'] as $id => $s) {
            if (!$s['active']) { continue; }
            $done = $latest !== '' && Integra_Sentinel_Dependencies::valid_version((string) $s['ver']) && !version_compare(ltrim($s['ver'], 'v'), ltrim($latest, 'v'), '<');
            $rings[$s['ring']]['total'] = ($rings[$s['ring']]['total'] ?? 0) + 1;
            $rings[$s['ring']]['done'] = ($rings[$s['ring']]['done'] ?? 0) + ($done ? 1 : 0);
        }
        ksort($rings);
        $current = null;
        foreach ($rings as $ring => $r) { if ($r['done'] < $r['total']) { $current = $ring; break; } }
        return array('rings' => $rings, 'current' => $current, 'latest' => $latest);
    }

    public static function ring_labels() {
        return array(0 => 'Ondata 0 · sito agenzia / test', 1 => 'Ondata 1 · siti vetrina', 2 => 'Ondata 2 · siti importanti', 3 => 'Ondata 3 · e-commerce e critici');
    }
}
