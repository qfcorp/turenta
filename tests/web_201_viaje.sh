# TuRenta (migración 201): pantallas del tiempo de viaje con mapa y del montaje/desmontaje por equipo.
# El sitio de prueba no sale a internet: los puntos y viajes se dejan en mapa_cache (lo que el programa ya buscó antes).

registrar quinta "Zombie Ice Web $RANDOM"; pagar_plan quinta
SLUG="zi$RANDOM"
F=$(sql "SELECT (CURRENT_DATE + 20)::text")
sql "UPDATE negocios SET slug='$SLUG', reserva_en_linea=true, idioma_publico='en', horas_montaje=1, horas_desmontaje=1,
       reserva_hora_desde='10:00', reserva_hora_hasta='17:00', reserva_paso_min=60, reserva_dias_min=2, telefono='(754) 777-0423',
       viaje_mapa=true, base_direccion='2000 N Commerce Pkwy, Weston, FL 33326', base_lat=26.1, base_lng=-80.4, viaje_extra_min=10, viaje_max_min=60
     WHERE id=$NID" > /dev/null
sql "DELETE FROM paquete_espacios WHERE paquete_id IN (SELECT id FROM paquetes WHERE negocio_id=$NID)" > /dev/null
ESP=$(sql "INSERT INTO espacios (negocio_id, nombre, orden, montaje_min, desmontaje_min) VALUES ($NID, 'ZombieTruck grande', 0, 45, 30) RETURNING id" | head -1)
PROD=$(sql "INSERT INTO productos (negocio_id, categoria, nombre, unidad, precio, con_iva) VALUES ($NID, 'otros', 'Signature (1 hour)', 'evento', 495, false) RETURNING id" | head -1)
PAQ=$(sql "INSERT INTO paquetes (negocio_id, nombre, slug, publico, duracion_min, maximo_invitados, orden) VALUES ($NID, 'Signature Experience', 'signature', true, 60, 50, 1) RETURNING id" | head -1)
sql "INSERT INTO paquete_items (paquete_id, producto_id, cantidad) VALUES ($PAQ, $PROD, 1); INSERT INTO paquete_espacios (paquete_id, espacio_id) VALUES ($PAQ, $ESP)" > /dev/null
# Lo que el mapa ya respondió: "Cerca" a 20 min y "Lejos" a 75 min (con 10 de margen: 30 y 85; el máximo es 60)
sql "INSERT INTO mapa_cache (negocio_id, tipo, clave, lat, lng, metodo) VALUES
       ($NID, 'punto', '10 cerca st, weston 33326', 26.11, -80.39, 'Google'),
       ($NID, 'punto', '9 lejos rd, naples 34102', 26.9, -80.9, 'Google');
     INSERT INTO mapa_cache (negocio_id, tipo, clave, lat, lng, minutos, km, metodo) VALUES
       ($NID, 'viaje', '26.10000,-80.40000>26.11000,-80.39000|$F', 26.11, -80.39, 20, 9.5, 'Google (tráfico)'),
       ($NID, 'viaje', '26.10000,-80.40000>26.90000,-80.90000|$F', 26.9, -80.9, 75, 110, 'Google (tráfico)')" > /dev/null
rm -f "$JAR"

echo "1. Página de reserva: la dirección va antes de la hora"
check "La reserva abre" "200" "$(get "r=reservar&s=$SLUG")"
contiene "Paso 2 pregunta dónde es la fiesta" "$TMP/pag.html" "Where is the party?"
contiene "… y avisa que las horas cuentan el viaje" "$TMP/pag.html" "open times include our drive"
contiene "El formulario sabe que usa el mapa" "$TMP/pag.html" 'data-mapa="1"'

echo "2. Horas según la dirección"
get "r=reservar&s=$SLUG&json=horas&p=signature&fecha=$F" > /dev/null
contiene "Sin dirección pide la dirección" "$TMP/pag.html" '"falta_direccion":true'
get "r=reservar&s=$SLUG&json=horas&p=signature&fecha=$F&direccion=10+Cerca+St,+Weston&zip=33326" > /dev/null
contiene "Cerca: salen horas" "$TMP/pag.html" '"hora":"10:00"'
contiene "… hasta la última" "$TMP/pag.html" '"hora":"17:00"'
get "r=reservar&s=$SLUG&json=horas&p=signature&fecha=$F&direccion=9+Lejos+Rd,+Naples&zip=34102" > /dev/null
contiene "Lejos: fuera del área en línea" "$TMP/pag.html" "outside our online booking area"
contiene "… con el teléfono para llamar" "$TMP/pag.html" "(754) 777-0423"
no_contiene "… y sin horas" "$TMP/pag.html" '"hora":"10:00"'

echo "3. Reservar guarda el viaje y ocupa el camión con su montaje"
R=$(post "r=reservar&s=$SLUG" --data-urlencode "paquete=signature" --data-urlencode "fecha=$F" --data-urlencode "hora=13:00" \
     --data-urlencode "nombre=Laura Gomez" --data-urlencode "telefono=(954) 555-0123" --data-urlencode "email=laura@example.com" \
     --data-urlencode "direccion=10 Cerca St, Weston" --data-urlencode "zip=33326" --data-urlencode "ninos=20" --data-urlencode "acepto=1")
contiene "Manda al portal para pagar" <(echo "$R") "r=portal"
EV=$(sql "SELECT id FROM eventos WHERE negocio_id=$NID AND direccion='10 Cerca St, Weston' ORDER BY id DESC LIMIT 1")
check "Viaje guardado: 20 + 10 de margen" "30" "$(sql "SELECT viaje_min FROM eventos WHERE id=$EV")"
check "Camión ocupado 13:00 − 30 − 45 hasta 14:00 + 30 + 30" "[\"$F 11:45:00\",\"$F 15:00:00\")" "$(sql "SELECT periodo::text FROM evento_espacios WHERE evento_id=$EV")"
get "r=reservar&s=$SLUG&json=horas&p=signature&fecha=$F&direccion=10+Cerca+St,+Weston&zip=33326" > /dev/null
no_contiene "Otra fiesta cerca: las 13:00 ya no salen" "$TMP/pag.html" '"hora":"13:00"'
contiene "… pero las 17:00 sí" "$TMP/pag.html" '"hora":"17:00"'
R=$(post "r=reservar&s=$SLUG" --data-urlencode "paquete=signature" --data-urlencode "fecha=$F" --data-urlencode "hora=17:00" \
     --data-urlencode "nombre=Ana Lopez" --data-urlencode "telefono=(954) 555-0199" --data-urlencode "email=ana@example.com" \
     --data-urlencode "direccion=9 Lejos Rd, Naples" --data-urlencode "zip=34102" --data-urlencode "ninos=20" --data-urlencode "acepto=1")
contiene "Reserva lejos vuelve al formulario" <(echo "$R") "r=reservar"
check "… y no se crea" "0" "$(sql "SELECT count(*) FROM eventos WHERE negocio_id=$NID AND direccion='9 Lejos Rd, Naples'")"

echo "4. Configuración → Reserva en línea: tiempo de viaje"
entrar "$EMAIL" claveSegura1
get "r=configuracion&tab=reserva" > /dev/null
contiene "Sección del tiempo de viaje" "$TMP/pag.html" "Tiempo de viaje con mapa"
contiene "Explica cómo sacar la clave de Google" "$TMP/pag.html" "Routes API"
contiene "Muestra la base en Google Maps" "$TMP/pag.html" "google.com/maps?q=26.1"
R=$(post_desde "r=configuracion&tab=reserva" "r=configuracion&tab=reserva&accion=viaje" \
     --data-urlencode "viaje_mapa=1" --data-urlencode "base_direccion=Bodega nueva" --data-urlencode "base_lat=26.2" --data-urlencode "base_lng=-80.3" \
     --data-urlencode "viaje_extra_min=15" --data-urlencode "viaje_max_min=75")
check "Guarda la base escrita a mano" "26.200000|-80.300000|15|75" "$(sql "SELECT base_lat||'|'||base_lng||'|'||viaje_extra_min||'|'||viaje_max_min FROM negocios WHERE id=$NID")"
post_desde "r=configuracion&tab=reserva" "r=configuracion&tab=reserva&accion=viaje" --data-urlencode "viaje_mapa=1" --data-urlencode "google_maps_key=AIza12" > /dev/null
get "r=configuracion&tab=reserva" > /dev/null
contiene "Clave cortada: avisa el error" "$TMP/pag.html" "no parece completa"

echo "5. Espacios: montaje y desmontaje del equipo"
get "r=espacios&editar=$ESP" > /dev/null
contiene "El equipo muestra su montaje" "$TMP/pag.html" 'name="montaje_min"'
contiene "… con la explicación" "$TMP/pag.html" "un camión grande 45 min"
R=$(post_desde "r=espacios&editar=$ESP" "r=espacios&accion=guardar" --data-urlencode "id=$ESP" --data-urlencode "nombre=ZombieTruck grande" \
     --data-urlencode "montaje_min=60" --data-urlencode "desmontaje_min=30" --data-urlencode "orden=0" --data-urlencode "color=#3E7C59")
check "Guarda 60 de montaje" "60" "$(sql "SELECT montaje_min FROM espacios WHERE id=$ESP")"
check "… y el evento futuro de ese camión se recalcula" "[\"$F 11:30:00\",\"$F 15:00:00\")" "$(sql "SELECT periodo::text FROM evento_espacios WHERE evento_id=$EV")"
get "r=espacios" > /dev/null
contiene "La tarjeta del equipo muestra sus minutos" "$TMP/pag.html" "Montaje 1 h"

echo "6. Sin viaje con mapa la reserva es como antes"
sql "UPDATE negocios SET viaje_mapa=false WHERE id=$NID" > /dev/null
rm -f "$JAR"
get "r=reservar&s=$SLUG" > /dev/null
no_contiene "Sin data-mapa" "$TMP/pag.html" 'data-mapa="1"'
get "r=reservar&s=$SLUG&json=horas&p=signature&fecha=$F" > /dev/null
contiene "Horas sin dirección" "$TMP/pag.html" '"hora":"10:00"'
