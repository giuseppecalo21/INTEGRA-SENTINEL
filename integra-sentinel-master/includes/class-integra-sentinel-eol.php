<?php
/**
 * Integra Sentinel — date di fine supporto (EOL) di PHP e database.
 *
 * Sostituisce le soglie fisse nel codice: una versione diventa "fuori supporto" alla sua data, non a ogni
 * nuova release del plugin. La tabella si puo' aggiornare senza rilasci con il filtro integra_sentinel_eol_table.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_EOL {

    const SOON_DAYS = 120;

    public static function table() {
        return apply_filters('integra_sentinel_eol_table', array(
            // Fine del supporto di sicurezza (php.net, politica di rilascio 2024: 31 dicembre).
            'php' => array('5.6' => '2018-12-31', '7.0' => '2019-01-10', '7.1' => '2019-12-01', '7.2' => '2020-11-30', '7.3' => '2021-12-06',
                '7.4' => '2022-11-28', '8.0' => '2023-11-26', '8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31',
                '8.4' => '2028-12-31', '8.5' => '2029-12-31'),
            'mysql' => array('5.5' => '2018-12-31', '5.6' => '2021-02-28', '5.7' => '2023-10-31', '8.0' => '2026-04-30', '8.1' => '2023-10-25',
                '8.2' => '2024-01-16', '8.3' => '2024-04-30', '8.4' => '2032-04-30'),
            'mariadb' => array('10.2' => '2022-05-23', '10.3' => '2023-05-25', '10.4' => '2024-06-18', '10.5' => '2025-06-24',
                '10.6' => '2026-07-06', '10.11' => '2028-02-16', '11.4' => '2029-05-29'),
        ));
    }

    /**
     * @return array branch, eol (data o ''), state: eol | soon | ok | unknown, days (giorni alla scadenza, negativi se scaduta)
     */
    public static function evaluate($type, $version) {
        $out = array('branch' => '', 'eol' => '', 'state' => 'unknown', 'days' => 0);
        if (!preg_match('/^(\d+)\.(\d+)/', (string) $version, $m)) { return $out; }
        $out['branch'] = $m[1] . '.' . $m[2];
        $table = self::table();
        $eol = $table[$type][$out['branch']] ?? '';
        if ($eol === '') { return $out; }
        $out['eol'] = $eol;
        $days = (int) floor((strtotime($eol . ' 23:59:59 UTC') - time()) / DAY_IN_SECONDS);
        $out['days'] = $days;
        $out['state'] = $days < 0 ? 'eol' : ($days <= self::SOON_DAYS ? 'soon' : 'ok');
        return $out;
    }

    public static function label($type) {
        return array('php' => 'PHP', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB')[$type] ?? strtoupper($type);
    }

    /** Frase leggibile, es. "PHP 8.2: supporto di sicurezza fino al 31/12/2026 (tra 69 giorni)". */
    public static function sentence($type, $version) {
        $e = self::evaluate($type, $version);
        $name = self::label($type) . ' ' . ($e['branch'] ?: $version);
        if ($e['state'] === 'unknown') { return $name . ': data di fine supporto non in tabella.'; }
        $date = wp_date('d/m/Y', strtotime($e['eol'] . ' 12:00:00 UTC'));
        if ($e['state'] === 'eol') { return $name . ': fuori supporto di sicurezza dal ' . $date . '.'; }
        if ($e['state'] === 'soon') { return $name . ': supporto di sicurezza fino al ' . $date . ' (tra ' . $e['days'] . ' giorni).'; }
        return $name . ': supportato fino al ' . $date . '.';
    }
}
