<?php
/**
 * Integra Sentinel — lavori giornalieri del Master: metriche per gli andamenti, pulizia dell'archivio
 * e bozze automatiche dei resoconti. Avviati dall'audit orario.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Jobs {

    const LAST_DAILY = 'integra_sentinel_last_daily';
    const EMERGENCIES = 'integra_sentinel_emergencies';

    public static function maybe_daily($force = false) {
        $today = wp_date('Y-m-d');
        if (!$force && get_option(self::LAST_DAILY) === $today) { return false; }
        if (!Integra_Sentinel_Master::acquire_lock('daily_jobs', 0)) { return false; }
        try {
            if (!$force && Integra_Sentinel_Master::fresh_option(self::LAST_DAILY, '') === $today) { return false; }
            update_option(self::LAST_DAILY, $today, false);
            Integra_Sentinel_Analysis::reset();
            Integra_Sentinel_Fleet::reset();
            $fleet = Integra_Sentinel_Fleet::aggregate();
            self::snapshot_metrics($today, $fleet);
            Integra_Sentinel_Store::cleanup();
            Integra_Sentinel_Reports::maybe_auto_drafts();
            return true;
        } finally {
            Integra_Sentinel_Master::release_lock('daily_jobs');
        }
    }

    public static function snapshot_metrics($day, $fleet) {
        $sites = Integra_Sentinel_Master::sites();
        foreach ($fleet['per_site'] as $id => $a) {
            $s = $a['summary'];
            $site = $sites[$id] ?? array();
            Integra_Sentinel_Store::save_metrics($day, $id, array(
                'updates' => (int) $s['updates'], 'ready' => (int) $s['ready'], 'blocked' => (int) $s['blocked'], 'license' => (int) $s['license'],
                'manual' => (int) $s['manual'], 'blind' => (int) $s['blind'], 'bottlenecks' => (int) $s['bottlenecks'], 'critical' => (int) $s['critical'],
                'vulns' => count(Integra_Sentinel_Master::sn_group_findings($site['findings'] ?? array())),
                'integrity' => count((array) ($site['integrity_findings'] ?? array())), 'minutes' => (int) $s['minutes'],
            ));
        }
    }

    /**
     * Emergenza: nuove vulnerabilita' critiche o alte sullo stesso componente in piu' siti → un solo avviso
     * con la vista per prodotto, oltre agli alert dei singoli siti.
     * @param array $fresh site_id => findings nuovi confermati
     * @param bool  $mail  false se gli alert email sono disattivati (restano i webhook)
     */
    public static function emergency($fresh, $sites, $mail = true) {
        $by_component = array();
        foreach ($fresh as $site_id => $findings) {
            foreach (Integra_Sentinel_Master::sn_group_findings($findings) as $g) {
                if (!in_array($g['max_tier'], array('critical', 'high'), true)) { continue; }
                $by_component[$g['key']]['name'] = $g['name'];
                $by_component[$g['key']]['type'] = $g['type'];
                $by_component[$g['key']]['slug'] = $g['slug'];
                $by_component[$g['key']]['tier'] = $g['max_tier'];
                $by_component[$g['key']]['sites'][$site_id] = (string) ($sites[$site_id]['label'] ?? $site_id);
                foreach ($g['items'] as $f) { $by_component[$g['key']]['ids'][(string) ($f['id'] ?? '')] = true; }
            }
        }
        $sent_log = get_option(self::EMERGENCIES, array());
        $sent_log = is_array($sent_log) ? $sent_log : array();
        foreach ($by_component as $key => $c) {
            if (count($c['sites']) < 2) { continue; }
            $signature = md5($key . '|' . implode(',', array_keys($c['ids'])));
            if (isset($sent_log[$signature])) { continue; }
            $url = admin_url('admin.php?page=integra-sentinel-fleet&product=' . rawurlencode($c['type'] . ':' . $c['slug']));
            $title = 'Emergenza: ' . $c['name'] . ' vulnerabile su ' . count($c['sites']) . ' siti';
            $text = 'Siti coinvolti: ' . implode(', ', array_slice($c['sites'], 0, 12)) . (count($c['sites']) > 12 ? '…' : '') . '. Gravità massima: ' . Integra_Sentinel_Master::sn_severity($c['tier']) . '.';
            $body = Integra_Sentinel_Master::mail_notice('MODALITÀ EMERGENZA', $text . ' Apri la vista per prodotto per vedere dove si può aggiornare subito e dove ci sono blocchi.', 'danger')
                . Integra_Sentinel_Master::mail_cta('Apri la vista ' . $c['name'], $url);
            $ok = $mail && Integra_Sentinel_Master::send_html('[Integra Sentinel] ' . $title, Integra_Sentinel_Master::mail_shell($title, 'Stessa vulnerabilità su più siti del parco', $body, 'danger'));
            $ok = Integra_Sentinel_Notify::send('emergency', $title, $text, $url) || $ok;
            if ($ok) { $sent_log[$signature] = time(); }
        }
        update_option(self::EMERGENCIES, array_slice($sent_log, -300, null, true), false);
    }
}
