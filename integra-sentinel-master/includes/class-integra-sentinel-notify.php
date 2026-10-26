<?php
/**
 * Integra Sentinel — notifiche oltre all'email: webhook compatibile Slack (Slack, Mattermost, Google Chat,
 * Discord con /slack), bot Telegram e webhook JSON generico (Make, Zapier, n8n). URL e token sono cifrati.
 */

if (!defined('ABSPATH')) { exit; }

final class Integra_Sentinel_Notify {

    public static function events() {
        return array(
            'integrity' => 'Integrità e possibili intrusioni',
            'cve' => 'Nuove vulnerabilità sui siti',
            'emergency' => 'Emergenza: stessa vulnerabilità su più siti',
            'down' => 'Sito non raggiungibile o Client muto',
            'drafts' => 'Bozze dei resoconti pronte da rileggere',
        );
    }

    public static function channels() {
        $cfg = Integra_Sentinel_Settings::get('webhooks');
        $slack = !empty($cfg['slack']) ? Integra_Sentinel_Master::decrypt_secret($cfg['slack']) : '';
        $generic = !empty($cfg['generic']) ? Integra_Sentinel_Master::decrypt_secret($cfg['generic']) : '';
        $token = !empty($cfg['telegram_token']) ? Integra_Sentinel_Master::decrypt_secret($cfg['telegram_token']) : '';
        return array('slack' => $slack, 'generic' => $generic, 'telegram_token' => $token, 'telegram_chat' => (string) ($cfg['telegram_chat'] ?? ''),
            'events' => (array) ($cfg['events'] ?? array()));
    }

    public static function configured() {
        $c = self::channels();
        return $c['slack'] !== '' || $c['generic'] !== '' || ($c['telegram_token'] !== '' && $c['telegram_chat'] !== '');
    }

    /** Invia l'evento ai canali configurati; ritorna true se almeno un canale ha accettato il messaggio. */
    public static function send($event, $title, $text, $url = '', $force = false) {
        $c = self::channels();
        if (!$force && !in_array($event, $c['events'], true)) { return false; }
        $title = wp_strip_all_tags((string) $title);
        $text = wp_strip_all_tags((string) $text);
        $ok = false;
        if ($c['slack'] !== '') {
            $ok = self::post($c['slack'], array('text' => '*' . $title . "*\n" . $text . ($url !== '' ? "\n<" . esc_url_raw($url) . '|Apri in Integra Sentinel>' : ''))) || $ok;
        }
        if ($c['generic'] !== '') {
            $ok = self::post($c['generic'], array('source' => 'integra-sentinel', 'version' => Integra_Sentinel_Master::VERSION, 'event' => $event,
                'title' => $title, 'text' => $text, 'url' => $url !== '' ? esc_url_raw($url) : '', 'at' => gmdate('c'))) || $ok;
        }
        if ($c['telegram_token'] !== '' && $c['telegram_chat'] !== '') {
            $ok = self::post('https://api.telegram.org/bot' . rawurlencode($c['telegram_token']) . '/sendMessage', array(
                'chat_id' => $c['telegram_chat'], 'text' => $title . "\n\n" . $text . ($url !== '' ? "\n" . esc_url_raw($url) : ''), 'disable_web_page_preview' => true)) || $ok;
        }
        return $ok;
    }

    private static function post($url, $payload) {
        $response = wp_safe_remote_post($url, array('timeout' => 8, 'redirection' => 1, 'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload), 'limit_response_size' => 8192));
        if (is_wp_error($response)) { return false; }
        $code = (int) wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    /** Salva i canali cifrando URL e token; un campo vuoto mantiene il valore gia' salvato, "-" lo cancella. */
    public static function save($input) {
        $cfg = Integra_Sentinel_Settings::get('webhooks');
        foreach (array('slack', 'generic') as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value === '-') { $cfg[$key] = ''; continue; }
            if ($value === '') { continue; }
            $url = esc_url_raw($value, array('https'));
            if ($url === '' || strpos($url, 'https://') !== 0) { return false; }
            $cfg[$key] = Integra_Sentinel_Master::encrypt_secret($url);
        }
        $token = trim((string) ($input['telegram_token'] ?? ''));
        if ($token === '-') { $cfg['telegram_token'] = ''; }
        elseif ($token !== '') {
            if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{20,80}$/', $token)) { return false; }
            $cfg['telegram_token'] = Integra_Sentinel_Master::encrypt_secret($token);
        }
        $chat = trim((string) ($input['telegram_chat'] ?? ''));
        $cfg['telegram_chat'] = preg_match('/^(-?\d{3,20}|@[A-Za-z0-9_]{4,64})$/', $chat) ? $chat : '';
        $cfg['events'] = array_values(array_intersect(array_keys(self::events()), array_map('strval', (array) ($input['events'] ?? array()))));
        return Integra_Sentinel_Settings::save('webhooks', $cfg);
    }
}
