# LFW Patterns Library

Repository privata per la gestione e distribuzione centralizzata dei pattern Gutenberg e Full Site Editing (FSE) per i siti WordPress.

Questa repository funge da "Cloud Hub". Ospita i file `.json` dei layout, che vengono poi richiamati e installati dinamicamente sui vari siti client tramite il plugin custom **LFW Cloud Patterns**.

---

## ⚙️ Architettura e Funzionamento

Il sistema si basa su una comunicazione API a senso unico (Pull) tra GitHub e i siti WordPress:

1. **Il Cloud (GitHub):** Tutti i layout creati vengono esportati nativamente dall'editor FSE di WordPress sotto forma di file `.json` e caricati nella cartella `/patterns` di questa repository.
2. **Il Client (WordPress):** Il plugin *LFW Cloud Patterns* interroga le API REST di GitHub (tramite un Personal Access Token).
3. **La Sincronizzazione:** Il plugin scansiona la cartella, scarica i `.json` e li inietta nel Core di WordPress usando la funzione nativa `register_block_pattern()`.
4. **Il Risultato:** All'interno del Block Inserter e nell'Editor FSE dei siti client, comparirà in automatico la categoria personalizzata **LFW Patterns** con le anteprime visive pronte all'uso.

---

🚀 Workflow Operativo
---------------------

Aggiungere un nuovo Pattern
---------------------------

1.  Entra nell'editor FSE o Gutenberg di un sito qualsiasi.
    
2.  Disegna il tuo layout usando blocchi nativi.
    
3.  Se usi immagini, usa preferibilmente **URL pubblici** (es. _picsum.photos/id/XX/800/600_) tramite la funzione "Inserisci da URL" per evitare link rotti.
    
4.  Salva il blocco e vai in _Aspetto > Editor > Pattern > I miei pattern_.
    
5.  Clicca sui 3 puntini e seleziona **Esporta come JSON**.
    
6.  Rinomina il file in modo parlante (es. hero-dark-mode.json).
    
7.  Fai drag & drop del file nella cartella /patterns/ di questa repository e fai il **Commit**.
    
8.  Sul sito WordPress, clicca il bottone in alto **Sincronizza Pattern** per svuotare la cache e vedere il risultato.
    

Aggiornare un Pattern esistente
-------------------------------

1.  Modifica il pattern in WordPress e riesportalo in JSON.
    
2.  Carica il nuovo file in /patterns/ mantenendo **esattamente lo stesso nome** del vecchio file.
    
3.  GitHub sovrascriverà il file precedente.
    
4.  Fai il Commit e lancia la sincronizzazione sul sito client.
    

Eliminare un Pattern in massa
-----------------------------

Per eliminare più pattern contemporaneamente senza software esterni:

1.  Dalla home della repository, premi il tasto . (punto) sulla tastiera. Si aprirà l'editor web _GitHub.dev_.
    
2.  Apri la cartella /patterns/.
    
3.  Tieni premuto Ctrl / Cmd e clicca sui file da cancellare.
    
4.  Tasto destro > **Delete**.
    
5.  Vai nella tab _Source Control_ (a sinistra), inserisci un messaggio e clicca **Commit & Push**.
    

⚠️ Comportamento del Core (Cancellazione Pattern)
-------------------------------------------------

Il plugin usa pattern **Non-Sincronizzati** (regolari).Se elimini un file .json da questa repository:

*   Il pattern **scomparirà dall'Inseritore Blocchi** (i clienti non potranno più aggiungerlo a nuove pagine).
    
*   Il pattern **NON scomparirà dalle pagine esistenti**. Tutto il contenuto precedentemente impaginato sui siti dei clienti rimarrà perfettamente integro, poiché il codice viene fisicamente copiato nel database al momento dell'inserimento.
    

🛠️ Requisiti di Sistema (Plugin Client)
----------------------------------------

Per far funzionare il collegamento su un nuovo sito web, assicurati che:

*   Il plugin _LFW Cloud Patterns_ sia installato e attivo.
    
*   All'interno del file del plugin siano correttamente inseriti:
    
    *   Il tuo **Username** GitHub.
        
    *   Il nome esatto di questa **Repository**.
        
    *   Un **Personal Access Token (classic)** con permessi repo e scadenza impostata su _No Expiration_.

## 📁 Struttura della Repository

```text
/
├── README.md               # Questo file
└── patterns/               # Cartella target letta dal plugin
    ├── hero-home.json
    ├── about-team.json
    ├── cta-newsletter.json
    └── ...
