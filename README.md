# Balboa Wifi Spa PHP Script for getting and setting Spa states
## You can set the desired temperature, turn on pumps an lights on and off

I use this script to run the heater only, if my pv generator produces enough power.

### Installation
Change IP address in index.php on line 4 to the IP address from your balboa spa:

````
$spaClient = new SpaClient('192.168.178.127');
````

### Some examples to use

Set spa temperature to 20°C
````
http://<your-host>/path/to/spa/?setTemperature=20
````

Run spa pump1 low
````
http://<your-host>/path/to/spa/?setPump1=Low
````

Run spa pump2 high
````
http://<your-host>/path/to/spa/?setPump2=High
````
Turn off both spa pumps
````
http://<your-host>/path/to/spa/?setPump1=Off
http://<your-host>/path/to/spa/?setPump2=Off
````

Turn on spa light
````
http://<your-host>/path/to/spa/?setLight=On
````