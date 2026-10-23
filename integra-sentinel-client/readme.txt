=== Integra Sentinel Client ===
Contributors: Integra
Tags: monitoring, security, updates, inventory
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later

Client leggero in sola lettura per Integra Sentinel Master.
Autore: Integra — https://integracorporate.it/

Installazione: caricare il plugin e aprire Impostazioni > Integra Sentinel. Inserire URL Master, ID sito e chiave segreta generati dal Master. Inviare il primo inventario.

Avviso: versione iniziale da collaudare in staging prima di utilizzarla su siti di produzione.

Versione 1.1.0: in caso di HTTP 404 sul percorso /wp-json, ritenta via ?rest_route (compatibile con installazioni in sottocartella e permalink non riscritti). La schermata mostra anche il Master configurato e i risultati dei tentativi. Compatibile con Integra Sentinel Master 1.1.0.

Versione 1.6.0 (22 ottobre 2026): per ogni plugin invia anche il canale reale di aggiornamento letto dal controllo di WordPress (WordPress.org, updater del fornitore, Update URI, nessun canale), la presenza di updater commerciali noti (Freemius, EDD Software Licensing, Plugin Update Checker), le firme di ecosistema trovate nel file principale (max 48 KB, in cache per versione) e i plugin inclusi nel tema tramite TGMPA. Dei pacchetti premium si invia solo il dominio, mai l'URL completo (puo contenere chiavi di licenza). Richiede Integra Sentinel Master 1.7.0 per la mappa delle dipendenze; resta compatibile con i Master precedenti.

Versione 1.7.0 (23 ottobre 2026): il Client resta leggero e in sola lettura. Aggiunge all'inventario pochi segnali letti da dati che WordPress ha gia' in memoria: se il pacchetto di un aggiornamento e' scaricabile (licenza mancante o scaduta), lo stato della licenza di Elementor Pro, ACF PRO, Gravity Forms, WP Rocket e Divi/Extra (solo stato e scadenza, MAI la chiave), l'impronta SHA-256 del file principale dei plugin (in cache per versione), canale, licenza e child theme dei temi, l'ambiente che decide se gli aggiornamenti possono funzionare (DISALLOW_FILE_MODS, metodo di scrittura, permessi, spazio libero, memoria, WP-Cron, Site Health, aggiornamenti automatici, ultimo backup UpdraftPlus, database WooCommerce/Elementor in sospeso, template WooCommerce obsoleti) e l'MD5 di alcuni file del core. Nessuna chiamata esterna in piu': i confronti con WordPress.org, Envato e gli altri siti li fa il Master. Nuovo collegamento rapido con codice di abbinamento (Impostazioni → Integra Sentinel) e comandi WP-CLI "wp sentinel-client send|status|pair". Compatibile con Master 1.7 (i campi nuovi vengono ignorati); le funzioni nuove richiedono Master 1.8.0.

Versione 1.8.0 (24 ottobre 2026): il Client annota gli aggiornamenti che WordPress applica in automatico (tipo, slug e nuova versione, conservati 40 giorni) e li invia nell'inventario, cosi' il Master li distingue da quelli fatti dall'agenzia nello Storico e nei resoconti ai clienti. Nessuna chiamata esterna in piu'. Compatibile con i Master precedenti (il campo nuovo viene ignorato); la distinzione richiede Master 1.9.1.
