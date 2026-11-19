# pullpost

[![Licență: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

[English](README.md) · **Română** · [Pagina proiectului](https://cremenescu.ro/ro/pullpost/)

O aplicație web mică, pe care o găzduiești tu, care salvează pozele și video-urile unei postări de
Instagram ca fișiere: fiecare imagine dintr-un carusel și fiecare video dintr-un reel, la cea mai
mare rezoluție pe care o dă Instagram, fiecare fișier separat plus un ZIP cu toate.

Fără legătură cu Instagram sau Meta Platforms, Inc., neaprobat și nesponsorizat de ele.
„Instagram” e marcă înregistrată a Meta Platforms, Inc. și apare aici doar ca să spună cu ce
lucrează unealta.

## Ce face

- Lipești adresa unei postări (`/p/`), a unui reel (`/reel/`) sau `/tv/`. Parametrii ca
  `?img_index=` nu încurcă, și merge și doar codul postării.
- Serverul deschide postarea într-un Chromium headless, fără cont, citește datele postării din
  pagină și descarcă fiecare poză și video de pe CDN-ul Instagram, alegând varianta cea mai mare.
- Primești fiecare fișier separat și un ZIP. Numele fișierelor încep cu data postării, așa că se
  ordonează după dată într-un folder: `2026-11-18_<cont>_<cod>_01.jpg`.
- Totul rămâne în istoric până îl ștergi, o postare întreagă sau un singur fișier (ZIP-ul se
  reface fără el). Se descarcă o postare odată, iar pagina arată progresul pe loc.
- Două înfățișări, alese din Setări: o masă luminoasă cu fișierele așezate ca diapozitive, sau o
  imprimantă de bonuri. Amândouă urmează setarea light sau dark a sistemului.
- Interfața e în engleză și română, aleasă după browser și schimbabilă de pe orice pagină.

## Cum funcționează

Două containere care împart un volum de date:

- **web**: PHP 8.3 cu Apache și SQLite. Paginile, istoricul, fișierele și ZIP-urile.
- **engine**: Python 3 (doar biblioteca standard) și Chromium-ul din Debian. Comandă Chromium prin
  protocolul DevTools pe un pipe (`--remote-debugging-pipe`), deci nu se deschide niciun port de
  debugging. Fișierele se descarcă doar de pe CDN-ul Instagram (`*.fbcdn.net`,
  `*.cdninstagram.com`); orice altă adresă, sau o redirecționare în altă parte, e refuzată.

API-ul HTTP al motorului n-are autentificare proprie, așa că fișierul compose nu-i publică niciun
port: ajunge la el doar containerul web. Chromium rulează fără sandbox-ul lui, într-un container
fără capabilități, fără privilegii noi și cu limită de memorie.

## Instalare

Ai nevoie de Docker cu Compose v2. Imaginea motorului conține Chromium, deci e cea mai mare dintre
cele două.

```sh
git clone https://github.com/cremenescu/pullpost.git
cd pullpost
docker compose up -d --build
docker compose logs web
```

Ultima comandă arată un rând ca `pullpost: setup code for the first sign-in: ABCD-EFGH`. Deschide
`http://<mașina asta>:8080`, scrie codul, alege parola paginii (minimum 8 caractere) și confirmă
că ai citit avertismentul. Codul arată că serverul e al tău; se șterge după ce pui parola.

### Setări

Le pui într-un fișier `.env` lângă `compose.yaml`, apoi rulezi din nou `docker compose up -d`.

| Variabilă | Implicit | Ce face |
|---|---|---|
| `PULLPOST_PORT` | `8080` | Portul paginii. `127.0.0.1:8080` o ține doar pe mașina asta. |
| `TZ` | `UTC` | Fusul orar pentru datele din numele fișierelor și din pagini, de exemplu `Europe/Bucharest`. |
| `PULLPOST_TRUST_PROXY` | `0` | `1` în spatele unui reverse proxy: vezi mai jos. |

Încă două sunt citite de containere și le poți adăuga în `compose.yaml` dacă îți trebuie:
`PULLPOST_THEME` (`a` sau `b`, înfățișarea până alegi una în Setări) la `web` și `USER_AGENT`
(header-ul descărcărilor de pe CDN, implicit `pullpost/<versiune>`) la `engine`.

### În spatele unui reverse proxy

Aplicația are parola ei, dar e gândită pentru rețeaua de acasă sau pentru un proxy care pune
HTTPS, nu pentru un port deschis spre internet. În spatele unui proxy pui
`PULLPOST_PORT=127.0.0.1:8080` și `PULLPOST_TRUST_PROXY=1`, iar proxy-ul trimite
`X-Forwarded-Proto` și `X-Real-IP` (sau `X-Forwarded-For`). Atunci cookie-ul de sesiune e marcat
`Secure`, iar limita de parole greșite numără adresa vizitatorului, nu pe a proxy-ului.

### Comenzi de zi cu zi

```sh
# parolă uitată: alegi una nouă (deloghează toate browserele)
docker compose exec -u www-data web php bin/set-password.php

# actualizare: datele rămân în volum
git pull && docker compose up -d --build

# oprire, cu datele păstrate
docker compose down

# ștergi tot, inclusiv descărcările
docker compose down -v
```

Volumul de date e `pullpost_data`. Ca să-i faci backup:

```sh
docker run --rm -v pullpost_data:/data -v "$PWD":/backup busybox tar czf /backup/pullpost-data.tgz -C /data .
```

## Contul tău de Instagram (opțional)

Fără cont, unealta vede doar ce vede oricine fără cont. Unele postări se arată doar cuiva logat.
Pentru ele poți pune în Setări cookie-ul `sessionid` al contului tău de Instagram (într-un browser
logat pe instagram.com: developer tools, Application sau Storage, Cookies, `sessionid`). Motorul
încearcă oricum fiecare postare întâi fără cont și folosește sesiunea doar când Instagram cere
autentificare.

Citește riscul înainte: asta e acces automat cu contul logat, pe care termenii Instagram îl
interzic și pe care hotărârea de mai jos nu-l acoperă. Instagram poate cere verificări, poate
limita sau dezactiva contul acela, iar cookie-ul e o autentificare completă în el. Stă în volumul
de date, citibil doar de utilizatorul aplicației, și îl poți scoate oricând din Setări.

## Folosire responsabilă

- **Pentru uz personal.** Pozele și video-urile sunt ale autorilor lor. Păstrează doar ce ai
  dreptul să păstrezi: postările tale, conținut dat ție pentru asta, sau ce permite legea din țara
  ta. Nu republica ce descarci.
- **Termenii de utilizare Instagram** interzic colectarea conținutului prin mijloace automate fără
  permisiunea Instagram. Folosirea uneltei poate încălca acești termeni, iar răspunderea e a ta.
- **Fără cont**, unealta vede doar ce vede oricine fără cont. În *Meta Platforms v. Bright Data*
  (N.D. Cal., ianuarie 2024) instanța a reținut că termenii Meta nu interziceau colectarea datelor
  publice fără cont. N-a decis nimic despre colectarea cu cont logat. Asta nu e consultanță
  juridică, iar alte instanțe pot vedea lucrurile altfel.
- Unelte asemănătoare, precum [instaloader](https://github.com/instaloader/instaloader),
  [gallery-dl](https://github.com/mikf/gallery-dl) și [yt-dlp](https://github.com/yt-dlp/yt-dlp),
  sunt publice și întreținute de ani de zile.
- Instagram își schimbă paginile fără anunț. Unealta poate să nu mai meargă oricând și vine fără
  nicio garanție.

## Confidențialitate

Aplicația vorbește doar cu Instagram: pagina postării și CDN-ul. Nu are telemetrie, nu verifică
update-uri și nu încarcă fonturi sau scripturi de la terți; fonturile sunt în repository.
Descărcările, istoricul și sesiunea de Instagram (dacă o pui) rămân în volumul de date, la tine.

## Dezvoltare

Aplicația web rulează pe orice PHP 8.2 sau mai nou, cu extensiile `gd` și `pdo_sqlite`.
Verificările ei rulează offline, fără motor și fără rețea, pe un folder de date de unică folosință:

```sh
php bin/smoke.php
```

Motorul rulează și singur, cu un Chrome sau Chromium local:

```sh
DATA=/tmp/pullpost-data CHROME=/usr/bin/chromium BIND=127.0.0.1 PORT=8898 python3 -I engine/server.py
```

Apoi îndrepți aplicația web spre el cu `PULLPOST_ENGINE_URL=http://127.0.0.1:8898` și
`PULLPOST_DATA=/tmp/pullpost-data`, de exemplu cu serverul propriu al PHP:
`php -S 127.0.0.1:8080 -t public`.

Pentru pull request-uri și traduceri vezi [CONTRIBUTING.md](CONTRIBUTING.md), iar pentru
raportarea unei vulnerabilități [SECURITY.md](SECURITY.md).

## Licență

Copyright (C) 2026 Răzvan Cremenescu. pullpost e software liber, publicat sub
[GNU General Public License v2.0 sau ulterioară](LICENSE).

Fonturile din `public/assets/fonts/` (Schibsted Grotesk, Courier Prime, Martian Mono) sunt sub
SIL Open Font License 1.1; licențele lor stau lângă ele.
