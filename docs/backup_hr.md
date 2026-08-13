# Integracija backupa

Opcionalna Backup integracija registrira provider `api` za zahtjeve za API ključ i webhook pretplate. Vlasnici i ključevi povezuju se prijenosnim Auth identitetima pa se brojčani ID-evi pri povratu smiju promijeniti.

Pokušaji dostave webhooka, zapisi idempotentnosti, brojači ograničenja, redovi dostave audita i privremeno stanje zahtjeva namjerno se izostavljaju. To je izvršno stanje čije bi vraćanje moglo dvaput poslati stare webhookove.

Provider podržava arhivu cijelog sitea i pojedine komponente. Ovisi o Auth skupu i zato se vraća nakon Autha. Preflight otkriva nedostajući Auth provider prije bilo kakve promjene cilja.

Nakon povrata napravite novi testni zahtjev i test potpisanog webhooka umjesto ponavljanja arhiviranih dostava. Vidi [webhookove](webhooks_hr.md) i [sigurnost](security_hr.md).
