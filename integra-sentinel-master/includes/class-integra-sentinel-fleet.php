<?php
/**
 * Integra Sentinel — vista d'insieme del parco: colli di bottiglia per causa (una riga per sito e problema),
 * indicatori di aggiornabilita' e vista per plugin e tema.
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

    /** Chiave di un problema su un sito: ancora stabile per i link dalla scheda sito alla pagina Colli di bottiglia. */
    public static function item_key($site_id, $b) {
        return substr(md5($site_id . '|' . $b['kind'] . '|' . $b['subject']), 0, 10);
    }

    /**
     * Problemi del parco, una riga per sito e per problema: prima il sito, poi il componente.
     * @return array kinds (tipo => righe), total, sites (siti con problemi), kpi, kpi_sites, waived, per_site
     */
    public static function aggregate() {
        if (self::$memo !== null) { return self::$memo; }
        $sites = Integra_Sentinel_Master::sites();
        $by_kind = array(); $waived = array(); $per_site = array(); $with = array();
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
            $label = (string) ($site['label'] ?? $id);
            foreach ($a['bottlenecks'] as $b) {
                $by_kind[$b['kind']][] = $b + array('site_id' => $id, 'site_label' => $label, 'key' => self::item_key($id, $b));
                $with[$id] = true;
            }
            foreach ($a['waived'] as $b) { $waived[] = $b + array('site_id' => $id, 'site_label' => $label); }
        }
        foreach ($by_kind as &$list) {
            // Le righe "in attesa del fornitore" scendono in fondo; il resto per sito e poi per gravita'.
            usort($list, function($a, $b) {
                $wa = (int) (($a['mark']['type'] ?? '') === 'waiting' && !empty($a['mark']['live']));
                $wb = (int) (($b['mark']['type'] ?? '') === 'waiting' && !empty($b['mark']['live']));
                return ($wa <=> $wb) ?: strcasecmp($a['site_label'], $b['site_label']) ?: Integra_Sentinel_Analysis::compare($a, $b);
            });
        }
        unset($list);
        $ordered = array(); $total = 0;
        foreach (array_keys(self::kinds()) as $kind) { if (!empty($by_kind[$kind])) { $ordered[$kind] = $by_kind[$kind]; $total += count($by_kind[$kind]); } }
        return self::$memo = array('kinds' => $ordered, 'total' => $total, 'groups' => $total, 'sites' => count($with), 'kpi' => $kpi, 'kpi_sites' => $kpi_sites,
            'waived' => $waived, 'per_site' => $per_site);
    }

    /** Le righe piu' urgenti di tutto il parco, qualunque sia la causa (panoramica ed email). */
    public static function top_items($fleet, $limit = 6) {
        $all = array();
        foreach ($fleet['kinds'] as $list) {
            foreach ($list as $b) { if (($b['mark']['type'] ?? '') !== 'waiting' || empty($b['mark']['live'])) { $all[] = $b; } }
        }
        usort($all, array('Integra_Sentinel_Analysis', 'compare'));
        return array_slice($all, 0, $limit);
    }

    /**
     * Vista per prodotto: ogni plugin/tema del parco con versioni e stato su ogni sito.
     * @return array slug|type => name, type, sites (id => dati), latest, versions
     */
    public static function products($fleet) {
        $sites = Integra_Sentinel_Master::sites();
        $out = array();
        foreach ($fleet['per_site'] as $id => $a) {
            $site = $sites[$id] ?? array();
            foreach ($a['graph']['nodes'] as $n) {
                if (in_array($n['role'], array('env', 'missing'), true) || $n['origin'] === 'internal') { continue; }
                $key = 'plugin:' . $n['id'];
                if (!isset($out[$key])) { $out[$key] = array('type' => 'plugin', 'slug' => $n['id'], 'name' => $n['name'], 'sites' => array(), 'latest' => '', 'premium' => false, 'external' => false, 'envato' => false, 'vuln' => 0); }
                $p = &$out[$key];
                $p['premium'] = $p['premium'] || !empty($n['premium']);
                $p['external'] = $p['external'] || !in_array($n['origin'], array('wporg', 'internal', 'unknown'), true);
                $p['envato'] = $p['envato'] || !empty($n['envato']);
                $target = $n['new'] !== '' ? $n['new'] : $n['latest'];
                foreach (array($n['ver'], $target) as $v) {
                    if (Integra_Sentinel_Dependencies::valid_version((string) $v) && ($p['latest'] === '' || version_compare(ltrim($v, 'v'), ltrim($p['latest'], 'v'), '>'))) { $p['latest'] = (string) $v; }
                }
                if (!empty($n['vuln']['count'])) { ++$p['vuln']; }
                $p['sites'][$id] = array('label' => (string) ($site['label'] ?? $id), 'ver' => $n['ver'], 'new' => $n['new'], 'latest' => $n['latest'],
                    'status' => $n['status'], 'statusLabel' => $n['statusLabel'], 'active' => $n['active'], 'auto' => !empty($n['auto']),
                    'license' => $n['license'], 'vuln' => $n['vuln'], 'origin' => $n['originLabel'], 'quarantine' => $n['quarantine'], 'reasons' => $n['reasons']);
                unset($p);
            }
            foreach ($a['themes']['themes'] as $t) {
                $key = 'theme:' . $t['slug'];
                if (!isset($out[$key])) { $out[$key] = array('type' => 'theme', 'slug' => $t['slug'], 'name' => $t['name'], 'sites' => array(), 'latest' => '', 'premium' => false, 'external' => false, 'envato' => false, 'vuln' => 0); }
                $p = &$out[$key];
                $p['premium'] = $p['premium'] || !empty($t['premium']);
                $p['external'] = $p['external'] || !empty($t['premium']);
                $p['envato'] = $p['envato'] || !empty($t['envato']);
                $target = $t['update'] !== '' ? $t['update'] : $t['latest'];
                foreach (array($t['version'], $target) as $v) {
                    if (Integra_Sentinel_Dependencies::valid_version((string) $v) && ($p['latest'] === '' || version_compare(ltrim($v, 'v'), ltrim($p['latest'], 'v'), '>'))) { $p['latest'] = (string) $v; }
                }
                if (!empty($t['vuln']['count'])) { ++$p['vuln']; }
                $p['sites'][$id] = array('label' => (string) ($site['label'] ?? $id), 'ver' => $t['version'], 'new' => $t['update'], 'latest' => $t['latest'],
                    'status' => $t['status'], 'statusLabel' => Integra_Sentinel_Dependencies::status_label($t['status']), 'active' => $t['role'] !== '', 'auto' => false,
                    'license' => $t['license'], 'vuln' => $t['vuln'], 'origin' => $t['originLabel'], 'quarantine' => '', 'reasons' => $t['reasons']);
                unset($p);
            }
        }
        uasort($out, function($a, $b) { return (count($b['sites']) <=> count($a['sites'])) ?: strcasecmp($a['name'], $b['name']); });
        return $out;
    }
}
