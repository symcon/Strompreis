# Strompreis
Liest die aktuellen/vorhergesagten Strompreise von aWATTar, Tibber oder Epex Spot DE aus.

### Funktionsumfang

* Auslesen von Strompreisen verschiedener Anbieter
* Visueller Verlauf der Marktdaten
* Manuelle Eingabe von Steuern, Aufschlag und Grundpreis

### Software-Installation

* Über den Module Store das 'Strompreis'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen

### Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'Strompreis'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Anbieter  | Stromanbieter, von dem die Daten bezogen werden sollen
Markt  | Auswahl des Landes
Grundpreis | Grundpreis des Stromes 
Aufschlag | Prozentualer Aufschlag zum Marktpreis
Steuersatz | Steuersatz, welcher auf den Strom hinzukommt

### Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name   | Typ     | Beschreibung
------ | ------- | ------------
Marktdaten  | String | Daten zum anzeigen der Grafik
Aktueller Preis | Float | Aktueller Strompreis

#### Profile

Name   | Typ
------ | -------
Cent | Float

### Visualisierung

In der Kachelvisualisierung bietet das Module ein Balkendiagramm welches den Verlauf des Strompreises darstellt. 


### PHP-Befehlsreferenz

`boolean SPX_Update(integer $InstanzID);`
Aktualisiert die Daten der Statusvariablen.

Beispiel:
`SPX_Update(12345);`