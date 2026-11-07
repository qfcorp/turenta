#!/usr/bin/env bash
# Plano del salón (distribuciones por espacio, plano del evento unido a las mesas de invitados, guardar JSON con CSRF,
# PDF, portal de los novios, permisos por rol, plan Salón con candado, aislamiento) y metas de ventas (pantalla,
# avance en el Inicio, Reportes → Metas, CSV).
P80_FECHA=$(date -d '+50 days' +%Y-%m-%d)
P80_HASH=$(php -r 'echo password_hash("claveSegura1", PASSWORD_DEFAULT);')
P80_MESES=(x enero febrero marzo abril mayo junio julio agosto septiembre octubre noviembre diciembre)
P80_MES=${P80_MESES[$((10#$(date +%m)))]}

echo "1. Distribuciones (plantillas) de un espacio"
registrar quinta "Hacienda Plano $RANDOM"
P80_NID=$NID; P80_EMAIL=$EMAIL
P80_ESP=$(sql "SELECT id FROM espacios WHERE negocio_id=$NID ORDER BY orden LIMIT 1")
check "Planos del salón abre" "200" "$(get "r=plano")"
contiene "Formulario para crear la primera" "$TMP/pag.html" "Nueva distribución"
contiene "Ofrece empezar con un ejemplo" "$TMP/pag.html" "Ejemplo de boda"
r=$(post "r=plano" --data-urlencode "accion=crear_plantilla" --data-urlencode "espacio_id=$P80_ESP" --data-urlencode "nombre=Boda 150 redondas" \
  --data-urlencode "ancho=20" --data-urlencode "largo=30" --data-urlencode "ejemplo=boda")
P80_T=$(sql "SELECT id FROM planos WHERE negocio_id=$NID AND nombre='Boda 150 redondas'")
check "Crear distribución lleva al editor" "302 $BASE/?r=plano&p=$P80_T" "$r"
check "El ejemplo de boda viene armado" "t" "$(sql "SELECT count(*) > 10 FROM plano_elementos WHERE plano_id=$P80_T")"
check "Editor de la distribución" "200" "$(get "r=plano&p=$P80_T")"
contiene "Datos para el editor (JSON)" "$TMP/pag.html" "data-pl-json"
contiene "Botones para agregar" "$TMP/pag.html" 'data-pl-agregar="redonda"'
contiene "Agregar pista" "$TMP/pag.html" 'data-pl-agregar="pista"'
contiene "Se ve sin JavaScript (SVG del servidor)" "$TMP/pag.html" "<noscript><svg"
contiene "PDF para imprimir" "$TMP/pag.html" "r=pdf&amp;tipo=plano&amp;id=$P80_T"
post "r=plano" --data-urlencode "accion=crear_plantilla" --data-urlencode "espacio_id=$P80_ESP" --data-urlencode "nombre=Prueba" --data-urlencode "ancho=1" --data-urlencode "largo=30" > /dev/null
get "r=plano&espacio=$P80_ESP" > /dev/null
contiene "Medida mala: mensaje claro" "$TMP/pag.html" "debe estar entre 3 y 300 metros"
get "r=plano" > /dev/null
contiene "La lista muestra la distribución con su dibujo" "$TMP/pag.html" "pl-mini-svg"
contiene "Cuenta mesas y puestos" "$TMP/pag.html" "puestos"
get "r=espacios" > /dev/null
contiene "Espacios enlaza a sus planos" "$TMP/pag.html" "r=plano&amp;espacio=$P80_ESP"

echo "2. Guardar desde el editor (JSON con CSRF)"
P80_EL='[{"tipo":"redonda","x":3,"y":3,"an":1.8,"al":1.8,"rot":0,"etq":"Mesa 1","puestos":10},{"tipo":"pista","x":10,"y":10,"an":6,"al":6,"rot":0,"etq":""},{"tipo":"barra","x":18,"y":15,"an":4,"al":0.8,"rot":90,"etq":"Barra"}]'
P80_V=$(sql "SELECT version FROM planos WHERE id=$P80_T")
r=$(post_desde "r=plano&p=$P80_T" "r=plano&p=$P80_T" --data-urlencode "accion=guardar" --data-urlencode "version=$P80_V" --data-urlencode "elementos=$P80_EL")
check "Guardar responde 200" "200" "${r%% *}"
contiene "Responde JSON ok" "$TMP/post.html" '"ok":true'
contiene "Devuelve la versión nueva" "$TMP/post.html" "\"version\":$((P80_V+1))"
check "Quedan los 3 elementos" "3" "$(sql "SELECT count(*) FROM plano_elementos WHERE plano_id=$P80_T")"
check "Barra girada 90°" "90" "$(sql "SELECT rotacion FROM plano_elementos WHERE plano_id=$P80_T AND tipo='barra'")"
r=$(post_desde "r=plano&p=$P80_T" "r=plano&p=$P80_T" --data-urlencode "accion=guardar" --data-urlencode "version=$P80_V" --data-urlencode "elementos=[]")
check "Versión vieja: 409 (otra persona guardó)" "409" "${r%% *}"
contiene "Mensaje de otra persona" "$TMP/post.html" "Otra persona"
check "No borró nada" "3" "$(sql "SELECT count(*) FROM plano_elementos WHERE plano_id=$P80_T")"
r=$(post_desde "r=plano&p=$P80_T" "r=plano&p=$P80_T" --data-urlencode "accion=guardar" --data-urlencode 'elementos=[{"tipo":"redonda","x":50,"y":3,"an":1.8,"al":1.8,"puestos":10,"etq":"Mesa 9"}]')
check "Fuera del salón: 422" "422" "${r%% *}"
contiene "Fuera del salón: mensaje claro" "$TMP/post.html" "fuera del salón"
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" --data-urlencode "accion=guardar" --data-urlencode "csrf=malo" --data-urlencode "elementos=[]" "$BASE/?r=plano&p=$P80_T")
check "Sin CSRF válido: 400" "400" "$r"
contiene "Sin CSRF: pide recargar" "$TMP/post.html" "Recarga"
check "Sin CSRF no cambió nada" "3" "$(sql "SELECT count(*) FROM plano_elementos WHERE plano_id=$P80_T")"
check "Datos en JSON" "200" "$(get "r=plano&p=$P80_T&json=1")"
contiene "JSON con los elementos" "$TMP/pag.html" '"tipo":"pista"'
r=$(post_desde "r=plano&p=$P80_T" "r=plano&p=$P80_T" --data-urlencode "accion=datos" --data-urlencode "nombre=Boda 150 redondas" --data-urlencode "ancho=15" --data-urlencode "largo=30")
check "Achicar el salón" "15.00" "$(sql "SELECT ancho FROM planos WHERE id=$P80_T")"
check "La barra se acercó a la pared" "15.00" "$(sql "SELECT x FROM plano_elementos WHERE plano_id=$P80_T AND tipo='barra'")"
r=$(post_desde "r=plano&p=$P80_T" "r=plano&p=$P80_T" --data-urlencode "accion=duplicar")
check "Copiar la distribución" "1" "$(sql "SELECT count(*) FROM planos WHERE negocio_id=$NID AND nombre='Boda 150 redondas (copia)'")"
P80_T2=$(sql "SELECT id FROM planos WHERE negocio_id=$NID AND nombre='Boda 150 redondas (copia)'")
r=$(post_desde "r=plano&p=$P80_T2" "r=plano&p=$P80_T2" --data-urlencode "accion=borrar")
check "Borrar la copia" "0" "$(sql "SELECT count(*) FROM planos WHERE id=$P80_T2")"
check "PDF de la distribución" "200" "$(get "r=pdf&tipo=plano&id=$P80_T")"
check "Es un PDF" "%PDF" "$(head -c 4 "$TMP/pag.html")"

echo "3. Plano del evento unido a las mesas de invitados"
P80_EV=$(crear_evento_sql "$P80_FECHA" separado "$P80_ESP")
check "Pestaña Plano" "200" "$(get "r=plano&id=$P80_EV")"
contiene "Pestaña en el evento" "$TMP/pag.html" "r=plano&amp;id=$P80_EV"
contiene "Elegir una distribución" "$TMP/pag.html" "Usar esta"
contiene "O desde cero" "$TMP/pag.html" "Empezar plano vacío"
post_desde "r=invitados&id=$P80_EV&ver=mesas" "r=invitados&id=$P80_EV" --data-urlencode "accion=mesas_crear" --data-urlencode "cantidad=2" \
  --data-urlencode "puestos=8" --data-urlencode "prefijo=Mesa" --data-urlencode "ver=mesas" > /dev/null
get "r=plano&id=$P80_EV" > /dev/null
contiene "Avisa que usará sus mesas de invitados" "$TMP/pag.html" "ya tiene 2 mesas en invitados"
r=$(post_desde "r=plano&id=$P80_EV" "r=plano&id=$P80_EV" --data-urlencode "accion=evento_crear" --data-urlencode "plantilla_id=$P80_T")
check "Usar la distribución" "302 $BASE/?r=plano&id=$P80_EV" "$r"
P80_P=$(sql "SELECT id FROM planos WHERE evento_id=$P80_EV")
check "El evento tiene su plano" "evento" "$(sql "SELECT tipo FROM planos WHERE id=$P80_P")"
check "La mesa del plano es la Mesa 1 de invitados" "1" "$(sql "SELECT count(*) FROM plano_elementos pe JOIN mesas m ON m.id = pe.mesa_id WHERE pe.plano_id=$P80_P AND m.nombre='Mesa 1' AND m.evento_id=$P80_EV")"
check "La Mesa 1 respeta sus 8 puestos" "8" "$(sql "SELECT puestos FROM plano_elementos pe JOIN mesas m ON m.id = pe.mesa_id WHERE pe.plano_id=$P80_P AND m.nombre='Mesa 1'")"
get "r=plano&id=$P80_EV" > /dev/null
contiene "Editor del evento" "$TMP/pag.html" "data-pl-editor"
contiene "Resumen de puestos" "$TMP/pag.html" "data-pl-r=\"puestos\""
contiene "La Mesa 2 de invitados sale sin ubicar" "$TMP/pag.html" '"sin_ubicar":\[{"mesa_id"'
contiene "Enlace para sentar invitados" "$TMP/pag.html" "Sentar invitados"
P80_M1=$(sql "SELECT id FROM mesas WHERE evento_id=$P80_EV AND nombre='Mesa 1'")
P80_E1=$(sql "SELECT id FROM plano_elementos WHERE mesa_id=$P80_M1")
P80_V=$(sql "SELECT version FROM planos WHERE id=$P80_P")
P80_EL="[{\"id\":$P80_E1,\"tipo\":\"redonda\",\"x\":4,\"y\":4,\"an\":1.8,\"al\":1.8,\"rot\":0,\"etq\":\"Mesa de los novios\",\"puestos\":6,\"mesa_id\":$P80_M1},{\"tipo\":\"rectangular\",\"x\":8,\"y\":20,\"an\":2.4,\"al\":0.9,\"rot\":0,\"etq\":\"\",\"puestos\":8}]"
r=$(post_desde "r=plano&id=$P80_EV" "r=plano&p=$P80_P" --data-urlencode "accion=guardar" --data-urlencode "version=$P80_V" --data-urlencode "elementos=$P80_EL")
check "Guardar el plano del evento" "200" "${r%% *}"
check "Renombrar en el plano cambia la mesa de invitados" "Mesa de los novios|6" "$(sql "SELECT nombre||'|'||capacidad FROM mesas WHERE id=$P80_M1")"
check "Mesa nueva del plano = mesa nueva en invitados" "1" "$(sql "SELECT count(*) FROM plano_elementos pe JOIN mesas m ON m.id=pe.mesa_id WHERE pe.plano_id=$P80_P AND pe.tipo='rectangular' AND m.capacidad=8")"
check "Mesas: la de los novios, la nueva y la Mesa 2 (sin ubicar)" "3" "$(sql "SELECT count(*) FROM mesas WHERE evento_id=$P80_EV")"
get "r=invitados&id=$P80_EV&ver=mesas" > /dev/null
contiene "Invitados → Mesas muestra la mesa del plano" "$TMP/pag.html" "Mesa de los novios"
contiene "Invitados → Mesas dibuja el plano" "$TMP/pag.html" "Plano del salón"
contiene "Dibujo con ocupación" "$TMP/pag.html" "0/6"
post_desde "r=invitados&id=$P80_EV" "r=invitados&id=$P80_EV" --data-urlencode "accion=agregar" --data-urlencode "nombre=Familia Pérez" --data-urlencode "pases=4" --data-urlencode "mesa_id=$P80_M1" > /dev/null
check "JSON con la ocupación" "200" "$(get "r=plano&id=$P80_EV&json=1")"
contiene "La mesa muestra 4 sentados" "$TMP/pag.html" '"ocupados":4'
contiene "Con el nombre del invitado" "$TMP/pag.html" '"n":"Familia Pérez"'
get "r=invitados&id=$P80_EV" > /dev/null
contiene "Invitados enlaza al plano del salón" "$TMP/pag.html" "r=plano&amp;id=$P80_EV"
check "PDF del plano del evento" "200" "$(get "r=pdf&tipo=plano&id=$P80_P")"
check "Es un PDF" "%PDF" "$(head -c 4 "$TMP/pag.html")"

echo "4. Portal de los novios: el plano para mirar"
P80_TOK=$(sql "SELECT token_cliente FROM eventos WHERE id=$P80_EV")
P80_JAR=$JAR; JAR="$TMP/jar_p80_portal"; rm -f "$JAR"
check "Portal de invitados → Mesas" "200" "$(get "r=portal_invitados&t=$P80_TOK&ver=mesas")"
contiene "Los novios ven el plano" "$TMP/pag.html" "Plano del salón"
contiene "Con su mesa" "$TMP/pag.html" "Mesa de los novios"
no_contiene "Los novios no tienen el editor" "$TMP/pag.html" "data-pl-editor"
check "PDF del plano para los novios" "200" "$(get "r=portal_invitados&t=$P80_TOK&plano=pdf")"
check "Es un PDF" "%PDF" "$(head -c 4 "$TMP/pag.html")"
r=$(curl -s -o "$TMP/pag.html" -w "%{http_code}" -d "accion=guardar&elementos=[]" "$BASE/?r=plano&p=$P80_P")
check "Sin sesión no se guarda (va al login)" "302" "$r"
JAR=$P80_JAR

echo "5. Roles"
P80_VEND="vend80$RANDOM@prueba.ec"; P80_COORD="coord80$RANDOM@prueba.ec"; P80_CONS="cons80$RANDOM@prueba.ec"
sql "INSERT INTO usuarios (negocio_id, nombre, email, password_hash, rol) VALUES ($NID, 'Vera Vendedora', '$P80_VEND', '$P80_HASH', 'vendedor'),
     ($NID, 'Coco Coordinador', '$P80_COORD', '$P80_HASH', 'coordinador'), ($NID, 'Solo Mira', '$P80_CONS', '$P80_HASH', 'consulta')" > /dev/null
P80_GUARDA='[{"tipo":"columna","x":2,"y":2,"an":0.6,"al":0.6,"rot":0,"etq":""}]'
entrar "$P80_COORD" "claveSegura1"
check "El coordinador abre el plano del evento" "200" "$(get "r=plano&id=$P80_EV")"
contiene "El coordinador puede agregar" "$TMP/pag.html" 'data-pl-agregar="redonda"'
P80_V=$(sql "SELECT version FROM planos WHERE id=$P80_P")
r=$(post_desde "r=plano&id=$P80_EV" "r=plano&p=$P80_P" --data-urlencode "accion=guardar" --data-urlencode "version=$P80_V" \
  --data-urlencode "elementos=$(get "r=plano&id=$P80_EV&json=1" > /dev/null; php -r '$d=json_decode(file_get_contents($argv[1]),true); $d["elementos"][]=["tipo"=>"columna","x"=>2,"y"=>2,"an"=>0.6,"al"=>0.6,"rot"=>0,"etq"=>""]; echo json_encode($d["elementos"]);' "$TMP/pag.html")")
check "El coordinador guarda el plano del evento" "200" "${r%% *}"
check "Y queda guardado" "1" "$(sql "SELECT count(*) FROM plano_elementos WHERE plano_id=$P80_P AND tipo='columna'")"
check "El coordinador mira las distribuciones" "200" "$(get "r=plano&p=$P80_T")"
no_contiene "…pero no las cambia" "$TMP/pag.html" 'data-pl-agregar='
r=$(post_desde "r=mi_cuenta" "r=plano&p=$P80_T" --data-urlencode "accion=guardar" --data-urlencode "elementos=$P80_GUARDA")
check "El coordinador no guarda distribuciones: 403" "403" "${r%% *}"
r=$(post_desde "r=plano" "r=plano" --data-urlencode "accion=crear_plantilla" --data-urlencode "espacio_id=$P80_ESP" --data-urlencode "nombre=Del coordinador" --data-urlencode "ancho=10" --data-urlencode "largo=10")
check "El coordinador no crea distribuciones" "0" "$(sql "SELECT count(*) FROM planos WHERE nombre='Del coordinador'")"
check "El coordinador no entra a metas" "302" "$(get "r=metas" | head -c 3)"
entrar "$P80_VEND" "claveSegura1"
check "El vendedor abre el plano del evento" "200" "$(get "r=plano&id=$P80_EV")"
contiene "El vendedor puede editarlo" "$TMP/pag.html" 'data-pl-agregar="redonda"'
r=$(post_desde "r=mi_cuenta" "r=plano&p=$P80_T" --data-urlencode "accion=guardar" --data-urlencode "elementos=$P80_GUARDA")
check "El vendedor no cambia distribuciones: 403" "403" "${r%% *}"
check "El vendedor no pone metas" "403" "$(get "r=metas")"
entrar "$P80_CONS" "claveSegura1"
check "Consulta mira el plano" "200" "$(get "r=plano&id=$P80_EV")"
contiene "Consulta: solo para mirar" "$TMP/pag.html" "Solo para mirar"
no_contiene "Consulta: sin botones de agregar" "$TMP/pag.html" 'data-pl-agregar='
no_contiene "Consulta: no puede borrar el plano" "$TMP/pag.html" 'value="evento_borrar"'
r=$(post_desde "r=mi_cuenta" "r=plano&p=$P80_P" --data-urlencode "accion=guardar" --data-urlencode "elementos=[]")
check "Consulta no guarda: 403" "403" "${r%% *}"
check "Consulta no borró nada" "t" "$(sql "SELECT count(*) > 0 FROM plano_elementos WHERE plano_id=$P80_P")"
r=$(post_desde "r=mi_cuenta" "r=plano&id=$P80_EV" --data-urlencode "accion=evento_borrar")
check "Consulta no borra el plano: 403" "403" "${r%% *}"
check "Consulta no borra el plano" "1" "$(sql "SELECT count(*) FROM planos WHERE id=$P80_P")"
entrar "$P80_EMAIL" "claveSegura1"

echo "6. Aislamiento y plan Salón"
registrar quinta "Otra Quinta $RANDOM"
check "Otro negocio: la distribución ajena no existe" "404" "$(get "r=plano&p=$P80_T")"
check "Otro negocio: el evento ajeno no existe" "404" "$(get "r=plano&id=$P80_EV")"
r=$(post_desde "r=plano" "r=plano&p=$P80_P" --data-urlencode "accion=guardar" --data-urlencode "elementos=[]")
check "Otro negocio no guarda el plano ajeno: 404" "404" "${r%% *}"
check "El plano ajeno sigue entero" "t" "$(sql "SELECT count(*) > 0 FROM plano_elementos WHERE plano_id=$P80_P")"
get "r=pdf&tipo=plano&id=$P80_P" > /dev/null
contiene "Otro negocio no saca el PDF ajeno" "$TMP/pag.html" "No se encontró el plano"
registrar salon "Salón Chico $RANDOM"
pagar_plan salon
check "Plan Salón (sin el módulo Plano): 403" "403" "$(get "r=plano")"
contiene "Plan Salón: el módulo no está en su plan" "$TMP/pag.html" "Este módulo no está en tu plan"
P80_EVS=$(crear_evento_sql "$P80_FECHA" consulta)
get "r=plano&id=$P80_EVS" > /dev/null
contiene "Plan Salón: módulo apagado en la pestaña del evento (con la cabecera)" "$TMP/pag.html" "evento-cabeza"
r=$(post "r=plano" --data-urlencode "accion=crear_plantilla" --data-urlencode "espacio_id=$(sql "SELECT id FROM espacios WHERE negocio_id=$NID LIMIT 1")" --data-urlencode "nombre=No" --data-urlencode "ancho=10" --data-urlencode "largo=10")
check "Plan Salón no crea planos" "0" "$(sql "SELECT count(*) FROM planos WHERE negocio_id=$NID")"
entrar "$P80_EMAIL" "claveSegura1"; NID=$P80_NID

echo "7. Metas de ventas"
check "Metas abre (dueño)" "200" "$(get "r=metas")"
contiene "Meta de todos los meses" "$TMP/pag.html" "Meta de todos los meses"
contiene "Los 12 meses" "$TMP/pag.html" "Diciembre"
get "r=inicio" > /dev/null
contiene "Sin meta: el Inicio invita a ponerla" "$TMP/pag.html" "Ponle una meta de ventas"
P80_MESA=$(date +%Y-%m)
r=$(post "r=metas" --data-urlencode "accion=local" --data-urlencode "monto=10.000" --data-urlencode "eventos=4" --data-urlencode "mes_monto[$P80_MESA]=8000" --data-urlencode "mes_eventos[$P80_MESA]=")
check "Guardar metas redirige" "302" "${r%% *}"
check "Meta de siempre" "10000.00|4" "$(sql "SELECT monto||'|'||eventos FROM metas_ventas WHERE negocio_id=$NID AND mes IS NULL AND vendedor_id IS NULL")"
check "Meta del mes" "8000.00" "$(sql "SELECT monto FROM metas_ventas WHERE negocio_id=$NID AND mes='$P80_MESA' AND vendedor_id IS NULL")"
check "Los otros meses no se guardan (usan la de siempre)" "2" "$(sql "SELECT count(*) FROM metas_ventas WHERE negocio_id=$NID")"
post "r=metas" --data-urlencode "accion=local" --data-urlencode "monto=" --data-urlencode "eventos=3" > /dev/null
get "r=metas" > /dev/null
contiene "Eventos sin dólares: error claro" "$TMP/pag.html" "Pon también la meta en dólares"
check "El error no borró nada" "2" "$(sql "SELECT count(*) FROM metas_ventas WHERE negocio_id=$NID")"
P80_VID=$(sql "SELECT id FROM usuarios WHERE email='$P80_VEND'")
r=$(post "r=metas" --data-urlencode "accion=vendedores" --data-urlencode "v_monto[$P80_VID]=3000" --data-urlencode "v_eventos[$P80_VID]=")
check "Meta del vendedor" "3000.00" "$(sql "SELECT monto FROM metas_ventas WHERE vendedor_id=$P80_VID AND mes IS NULL")"
r=$(post "r=metas" --data-urlencode "accion=vendedor_mes" --data-urlencode "vendedor_id=$P80_VID" --data-urlencode "mes=$P80_MESA" --data-urlencode "monto=2500")
check "Meta de un mes para el vendedor" "2500.00" "$(sql "SELECT monto FROM metas_ventas WHERE vendedor_id=$P80_VID AND mes='$P80_MESA'")"
r=$(post "r=metas" --data-urlencode "accion=vendedor_mes" --data-urlencode "vendedor_id=999999" --data-urlencode "mes=$P80_MESA" --data-urlencode "monto=2500")
get "r=metas" > /dev/null
contiene "Vendedor ajeno: error" "$TMP/pag.html" "no es de tu negocio"
# Ventas: este mes $6.000 en 3 eventos (el de la sección 3 se separó hoy y vale $0; uno de la vendedora $2.000); el mismo mes del año pasado $5.000
P80_V1=$(crear_evento_sql "$(date -d '+70 days' +%Y-%m-%d)" separado "$P80_ESP")
P80_V2=$(crear_evento_sql "$(date -d '+77 days' +%Y-%m-%d)" confirmado "$P80_ESP")
P80_V3=$(crear_evento_sql "$(date -d '+84 days' +%Y-%m-%d)" separado "$P80_ESP")
sql "UPDATE eventos SET total=4000, creado_en=date_trunc('month', now()) + interval '1 hour', confirmado_en=NULL WHERE id=$P80_V1" > /dev/null
sql "UPDATE eventos SET total=2000, creado_en=date_trunc('month', now()) + interval '2 hours', confirmado_en=NULL, vendedor_id=$P80_VID WHERE id=$P80_V2" > /dev/null
sql "UPDATE eventos SET total=5000, creado_en=date_trunc('month', now()) - interval '1 year' + interval '3 hours', confirmado_en=NULL WHERE id=$P80_V3" > /dev/null
get "r=inicio" > /dev/null
contiene "Inicio: meta del mes" "$TMP/pag.html" "Meta de $P80_MES"
contiene "Inicio: vendido de la meta" "$TMP/pag.html" '\$6.000,00</strong> <span>de \$8.000,00'
contiene "Inicio: porcentaje" "$TMP/pag.html" "75 %"
contiene "Inicio: cuánto falta" "$TMP/pag.html" 'Faltan <strong>\$2.000,00'
contiene "Inicio: barra de avance" "$TMP/pag.html" 'role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="75"'
contiene "Inicio: comparación con el año pasado" "$TMP/pag.html" "+20 %"
get "r=reportes" > /dev/null
contiene "Reportes: sección Metas" "$TMP/pag.html" 'id="metas"'
contiene "Reportes: índice a Metas" "$TMP/pag.html" 'href="#metas"'
contiene "Reportes: meta y vendido del mes" "$TMP/pag.html" '\$8.000,00'
contiene "Reportes: cumplimiento" "$TMP/pag.html" "<b>75 %</b>"
contiene "Reportes: año anterior" "$TMP/pag.html" '\$5.000,00'
contiene "Reportes: cambio contra el año anterior" "$TMP/pag.html" "▲ +20 %"
contiene "Reportes: por vendedor" "$TMP/pag.html" "Vera Vendedora"
contiene "Reportes: descargar metas" "$TMP/pag.html" "r=metas&amp;csv=1"
P80_D=$(date +%Y-%m-01); P80_H=$(date -d "$P80_D +1 month -1 day" +%Y-%m-%d)
check "CSV de metas" "200" "$(get "r=metas&csv=1&desde=$P80_D&hasta=$P80_H")"
contiene "CSV: fila del local" "$TMP/pag.html" '"Todo el local"'
contiene "CSV: meta y vendido" "$TMP/pag.html" '8000,00;;6000,00;3;"75 %";5000,00;1;"20 %"'
entrar "$P80_VEND" "claveSegura1"
get "r=inicio" > /dev/null
contiene "La vendedora ve su meta" "$TMP/pag.html" "Tu meta de $P80_MES"
contiene "Con lo que ella vendió" "$TMP/pag.html" '\$2.000,00</strong> <span>de \$2.500,00'
get "r=reportes" > /dev/null
contiene "La vendedora ve su avance" "$TMP/pag.html" "Tu avance"
check "La vendedora descarga sus metas" "200" "$(get "r=metas&csv=1&desde=$P80_D&hasta=$P80_H")"
no_contiene "…sin las filas del local" "$TMP/pag.html" "Todo el local"
entrar "$P80_COORD" "claveSegura1"
get "r=inicio" > /dev/null
no_contiene "El coordinador no ve metas" "$TMP/pag.html" "mt-inicio"
entrar "$P80_CONS" "claveSegura1"
get "r=inicio" > /dev/null
contiene "Consulta ve la meta del local" "$TMP/pag.html" "Meta de $P80_MES"
no_contiene "Consulta no puede cambiar metas" "$TMP/pag.html" "Cambiar metas"
entrar "$P80_EMAIL" "claveSegura1"
