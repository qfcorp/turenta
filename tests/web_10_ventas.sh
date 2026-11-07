# Ventas, calendario y página pública: de punta a punta
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh
FUT=$(date -d "+45 days" +%Y-%m-%d)
FUT2=$(date -d "+80 days" +%Y-%m-%d)
ANIO=$(date -d "$FUT" +%Y); MES=$(date -d "$FUT" +%-m)

echo "1. Crear evento con cliente nuevo"
registrar quinta "Quinta Ventas $RANDOM"
NID_A=$NID; EMAIL_A=$EMAIL
SLUG=$(sql "SELECT slug FROM negocios WHERE id=$NID")
SALON=$(sql "SELECT id FROM espacios WHERE negocio_id=$NID AND nombre='Salón principal'")
JARDIN=$(sql "SELECT id FROM espacios WHERE negocio_id=$NID AND nombre='Jardín'")
check "Formulario de nuevo evento" "200" "$(get "r=evento_nuevo&fecha=$FUT")"
contiene "La fecha viene rellenada" "$TMP/pag.html" "value=\"$FUT\""
contiene "Casillas de espacios con capacidad" "$TMP/pag.html" "250 sentados"
contiene "Cómo nos conoció" "$TMP/pag.html" "Recomendación"
r=$(post_desde "r=evento_nuevo" "r=evento_nuevo" --data-urlencode "modo_cliente=nuevo" --data-urlencode "cliente_nombre=Ana Torres" \
     --data-urlencode "cliente_telefono=0991112233" --data-urlencode "cliente_identificacion=1712345678" --data-urlencode "origen=instagram" \
     --data-urlencode "tipo=boda" --data-urlencode "fecha=$FUT" --data-urlencode "hora_inicio=18:00" --data-urlencode "hora_fin=02:00" \
     --data-urlencode "invitados=150" --data-urlencode "espacios[]=$SALON" --data-urlencode "presupuesto_cliente=12.000" --data-urlencode "notas=Quieren ceremonia")
EV1=$(sql "SELECT id FROM eventos WHERE negocio_id=$NID ORDER BY id DESC LIMIT 1")
check "Crear lleva a la página del evento" "302 $BASE/?r=evento&id=$EV1" "$r"
check "Cliente nuevo guardado" "Ana Torres" "$(sql "SELECT c.nombre FROM eventos e JOIN clientes c ON c.id=e.cliente_id WHERE e.id=$EV1")"
check "Origen instagram" "instagram" "$(sql "SELECT origen FROM eventos WHERE id=$EV1")"
check "Presupuesto 12000" "12000.00" "$(sql "SELECT presupuesto_cliente FROM eventos WHERE id=$EV1")"
check "Página del evento" "200" "$(get "r=evento&id=$EV1")"
contiene "Nombre automático" "$TMP/pag.html" "Boda de Ana Torres"
contiene "Aviso de creado" "$TMP/pag.html" "Evento creado"
contiene "WhatsApp con un toque" "$TMP/pag.html" "wa.me/593991112233"
contiene "Botón de cotizar" "$TMP/pag.html" "Hacer cotización"
contiene "Enlace del portal del cliente" "$TMP/pag.html" "r=portal&amp;t="
contiene "Tareas de la plantilla" "$TMP/pag.html" "Degustación del menú"
contiene "Pestañas del evento" "$TMP/pag.html" "Día del evento"
r=$(post_desde "r=evento_nuevo" "r=evento_nuevo" --data-urlencode "modo_cliente=nuevo" --data-urlencode "cliente_nombre=" --data-urlencode "tipo=boda" \
     --data-urlencode "fecha=$FUT" --data-urlencode "invitados=10" --data-urlencode "espacios[]=$SALON")
contiene "Sin nombre: mensaje claro" "$TMP/post.html" "Escribe el nombre del cliente"
check "Con cliente existente" "200" "$(get "r=evento_nuevo&cliente_id=$(sql "SELECT cliente_id FROM eventos WHERE id=$EV1")")"
contiene "Cliente existente elegido" "$TMP/pag.html" "selected>Ana Torres"

echo "2. Disponibilidad interna (JSON) y choques"
check "JSON interno" "200" "$(get "r=disponible&fecha=$FUT&hora_inicio=19:00&hora_fin=23:00&espacios[]=$SALON")"
contiene "Choque con la consulta" "$TMP/pag.html" '"nivel":"consulta"'
contiene "Explica el choque" "$TMP/pag.html" "también preguntó"
get "r=disponible&fecha=$FUT&hora_inicio=19:00&hora_fin=23:00&espacios[]=$SALON&excluir=$EV1" > /dev/null
contiene "Excluyendo el propio evento: sin choques" "$TMP/pag.html" '"choques":\[\]'
get "r=disponible&fecha=$FUT&espacios[]=$JARDIN" > /dev/null
contiene "Jardín libre" "$TMP/pag.html" '"bloqueado":false'

echo "3. Calendario del mes con el evento"
check "Calendario del mes" "200" "$(get "r=calendario&a=$ANIO&m=$MES")"
contiene "El evento sale en su día" "$TMP/pag.html" "Boda de Ana Torres"
contiene "Coloreado por estado" "$TMP/pag.html" "cal-ev ev-consulta"
contiene "Leyenda" "$TMP/pag.html" "cal-leyenda"
check "Detalle del día" "200" "$(get "r=calendario&a=$ANIO&m=$MES&dia=$FUT")"
contiene "Disponibilidad por espacio" "$TMP/pag.html" "Hay consultas"
contiene "Botón nuevo evento este día" "$TMP/pag.html" "Nuevo evento este día"
get "r=calendario&a=$ANIO&m=$MES&espacio=$JARDIN" > /dev/null
no_contiene "Filtro por jardín oculta el evento del salón" "$TMP/pag.html" "Boda de Ana Torres"
check "Próximos sábados" "200" "$(get "r=calendario&vista=sabados")"
contiene "Lista de sábados" "$TMP/pag.html" "sabado-fecha"
contiene "Libre para vender" "$TMP/pag.html" "Libre · vender"
check "Mes que no existe: muestra el actual" "200" "$(get "r=calendario&a=9999&m=40")"

echo "4. Lista y tablero"
check "Lista de eventos" "200" "$(get "r=eventos")"
contiene "Evento en la lista" "$TMP/pag.html" "Boda de Ana Torres"
get "r=eventos&q=Torres" > /dev/null
contiene "Buscar por cliente" "$TMP/pag.html" "Boda de Ana Torres"
get "r=eventos&q=NoExiste123" > /dev/null
contiene "Búsqueda sin resultados" "$TMP/pag.html" "No hay eventos con estos filtros"
get "r=eventos&estado=confirmado" > /dev/null
no_contiene "Filtro por estado" "$TMP/pag.html" "Boda de Ana Torres"
get "r=eventos&tipo=quince" > /dev/null
no_contiene "Filtro por tipo" "$TMP/pag.html" "Boda de Ana Torres"
get "r=eventos&desde=$FUT&hasta=$FUT" > /dev/null
contiene "Filtro por fechas" "$TMP/pag.html" "Boda de Ana Torres"
check "Tablero" "200" "$(get "r=eventos&vista=tablero")"
contiene "Columna consulta" "$TMP/pag.html" "col-consulta"
contiene "Valor = presupuesto del cliente" "$TMP/pag.html" "presupuesto del cliente"

echo "5. Estados y doble reserva"
r=$(post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=estado" --data-urlencode "estado=cotizado")
check "Cambiar a cotizado vuelve al evento" "302 $BASE/?r=evento&id=$EV1" "$r"
check "Estado cotizado" "cotizado" "$(sql "SELECT estado FROM eventos WHERE id=$EV1")"
get "r=evento&id=$EV1" > /dev/null
contiene "Muestra la retención" "$TMP/pag.html" "Fecha retenida hasta"
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=retener" --data-urlencode "dias=10" > /dev/null
check "Extender retención 10 días" "$(date -d "+10 days" +%Y-%m-%d)" "$(sql "SELECT retenida_hasta FROM eventos WHERE id=$EV1")"
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=estado" --data-urlencode "estado=separado" > /dev/null
check "Separar" "separado" "$(sql "SELECT estado FROM eventos WHERE id=$EV1")"
check "El espacio queda bloqueado" "t" "$(sql "SELECT bloquea FROM evento_espacios WHERE evento_id=$EV1")"
# Otro evento el mismo día y espacio
r=$(post_desde "r=evento_nuevo" "r=evento_nuevo" --data-urlencode "modo_cliente=nuevo" --data-urlencode "cliente_nombre=Luis Mora" \
     --data-urlencode "cliente_telefono=0982223344" --data-urlencode "tipo=quince" --data-urlencode "fecha=$FUT" \
     --data-urlencode "hora_inicio=19:00" --data-urlencode "hora_fin=23:00" --data-urlencode "invitados=80" --data-urlencode "espacios[]=$SALON")
check "No se guarda sin confirmar el choque" "200 " "$r"
contiene "Mensaje claro del choque" "$TMP/post.html" "ya está separado por"
contiene "Ofrece guardar como consulta" "$TMP/post.html" "Guardarlo igual como consulta"
r=$(post_desde "r=evento_nuevo" "r=evento_nuevo" --data-urlencode "modo_cliente=nuevo" --data-urlencode "cliente_nombre=Luis Mora" \
     --data-urlencode "cliente_telefono=0982223344" --data-urlencode "tipo=quince" --data-urlencode "fecha=$FUT" \
     --data-urlencode "hora_inicio=19:00" --data-urlencode "hora_fin=23:00" --data-urlencode "invitados=80" --data-urlencode "espacios[]=$SALON" \
     --data-urlencode "aceptar_choque=1")
EV2=$(sql "SELECT id FROM eventos WHERE negocio_id=$NID ORDER BY id DESC LIMIT 1")
check "Guardado como consulta en lista de espera" "302 $BASE/?r=evento&id=$EV2" "$r"
get "r=evento&id=$EV2" > /dev/null
contiene "La ficha muestra el choque" "$TMP/pag.html" "Otros eventos a la misma hora"
post_desde "r=evento&id=$EV2" "r=evento&id=$EV2&accion=estado" --data-urlencode "estado=separado" > /dev/null
check "Doble reserva bloqueada" "consulta" "$(sql "SELECT estado FROM eventos WHERE id=$EV2")"
get "r=evento&id=$EV2" > /dev/null
contiene "Mensaje claro de doble reserva" "$TMP/pag.html" "ya está separado ese día por"
post_desde "r=evento&id=$EV2" "r=evento&id=$EV2&accion=estado" --data-urlencode "estado=perdido" --data-urlencode "motivo=" > /dev/null
check "Perdido sin motivo: no cambia" "consulta" "$(sql "SELECT estado FROM eventos WHERE id=$EV2")"
get "r=evento&id=$EV2" > /dev/null
contiene "Pide el motivo" "$TMP/pag.html" "Escribe el motivo"
post_desde "r=evento&id=$EV2" "r=evento&id=$EV2&accion=estado" --data-urlencode "estado=perdido" --data-urlencode "motivo=Fecha ocupada" > /dev/null
check "Perdido con motivo" "perdido|Fecha ocupada" "$(sql "SELECT estado||'|'||motivo_perdida FROM eventos WHERE id=$EV2")"
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=estado" --data-urlencode "estado=perdido" --data-urlencode "motivo=Prueba de paso" > /dev/null
get "r=evento&id=$EV1" > /dev/null
contiene "Paso no permitido: mensaje claro" "$TMP/pag.html" "No se puede pasar de"
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=estado" --data-urlencode "estado=inventado" > /dev/null
check "Estado inventado no cambia nada" "separado" "$(sql "SELECT estado FROM eventos WHERE id=$EV1")"
get "r=evento&id=$EV1" > /dev/null
contiene "Historial de cambios" "$TMP/pag.html" "Cambió el estado de Cotizado a Separado"

echo "6. Actividad, tarea, coordinador, detalles, editar y duplicar"
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=actividad" --data-urlencode "tipo=visita" --data-urlencode "texto=Visita al local con los novios" \
     --data-urlencode "programada=$(date -d "+2 days" +%Y-%m-%d)T10:00" > /dev/null
check "Actividad agendada" "visita|false" "$(sql "SELECT tipo||'|'||hecha FROM actividades WHERE evento_id=$EV1 AND texto LIKE 'Visita al local%'")"
ACT=$(sql "SELECT id FROM actividades WHERE evento_id=$EV1 AND texto LIKE 'Visita al local%'")
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=actividad" --data-urlencode "tipo=llamada" --data-urlencode "texto=Llamé para confirmar" > /dev/null
check "Nota sin fecha queda hecha" "t" "$(sql "SELECT hecha FROM actividades WHERE evento_id=$EV1 AND texto='Llamé para confirmar'")"
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=tarea" --data-urlencode "titulo=Prueba de vestido de la novia" --data-urlencode "vence=$(date -d "+20 days" +%Y-%m-%d)" > /dev/null
TAREA=$(sql "SELECT id FROM evento_tareas WHERE evento_id=$EV1 AND titulo='Prueba de vestido de la novia'")
check "Tarea agregada" "1" "$(sql "SELECT count(*) FROM evento_tareas WHERE id=$TAREA")"
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=tarea_marcar" --data-urlencode "tarea_id=$TAREA" --data-urlencode "hecha=1" > /dev/null
check "Tarea hecha" "t" "$(sql "SELECT hecha FROM evento_tareas WHERE id=$TAREA")"
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=detalles" --data-urlencode "menu_elegido=Menú tres tiempos" --data-urlencode "detalles_cliente=Colores dorado y blanco" > /dev/null
check "Detalles guardados" "Colores dorado y blanco" "$(sql "SELECT detalles_cliente FROM eventos WHERE id=$EV1")"
UID_D=$(sql "SELECT id FROM usuarios WHERE email='$EMAIL_A'")
post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=coordinador" --data-urlencode "coordinador_id=$UID_D" > /dev/null
check "Coordinador asignado" "$UID_D" "$(sql "SELECT coordinador_id FROM eventos WHERE id=$EV1")"
check "Editar evento" "200" "$(get "r=evento_editar&id=$EV1")"
contiene "Editar trae los datos" "$TMP/pag.html" 'value="150"'
r=$(post_desde "r=evento_editar&id=$EV1" "r=evento_editar&id=$EV1" --data-urlencode "modo_cliente=existente" \
     --data-urlencode "cliente_id=$(sql "SELECT cliente_id FROM eventos WHERE id=$EV1")" --data-urlencode "tipo=boda" --data-urlencode "nombre=Boda de Ana y Luis" \
     --data-urlencode "fecha=$FUT" --data-urlencode "hora_inicio=17:00" --data-urlencode "hora_fin=01:00" --data-urlencode "invitados=180" \
     --data-urlencode "espacios[]=$SALON" --data-urlencode "espacios[]=$JARDIN" --data-urlencode "montaje=redondas" --data-urlencode "origen=instagram")
check "Editar vuelve al evento" "302 $BASE/?r=evento&id=$EV1" "$r"
check "Cambios guardados" "Boda de Ana y Luis|180|2" "$(sql "SELECT nombre||'|'||invitados||'|'||(SELECT count(*) FROM evento_espacios WHERE evento_id=$EV1) FROM eventos WHERE id=$EV1")"
check "El historial cuenta qué cambió" "1" "$(sql "SELECT count(*) FROM registro_cambios WHERE evento_id=$EV1 AND accion LIKE 'Editó el evento (%invitados 150 → 180%'")"
r=$(post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=duplicar" --data-urlencode "fecha=$FUT2")
EV3=$(sql "SELECT id FROM eventos WHERE negocio_id=$NID ORDER BY id DESC LIMIT 1")
check "Duplicar lleva al nuevo" "302 $BASE/?r=evento&id=$EV3" "$r"
check "Copia en consulta en la nueva fecha" "consulta|$FUT2" "$(sql "SELECT estado||'|'||fecha FROM eventos WHERE id=$EV3")"

echo "7. Seguimiento"
check "Pantalla de seguimiento" "200" "$(get "r=seguimiento")"
contiene "Visita en la agenda de la semana" "$TMP/pag.html" "Visita al local con los novios"
contiene "Copia sin atender aún no (recién creada)" "$TMP/pag.html" "Consultas sin atender"
sql "UPDATE eventos SET creado_en = now() - interval '5 days' WHERE id=$EV3" > /dev/null
get "r=seguimiento" > /dev/null
contiene "Consulta sin atender aparece" "$TMP/pag.html" "Boda de Ana y Luis"
post_desde "r=seguimiento" "r=seguimiento&accion=reagendar" --data-urlencode "actividad_id=$ACT" --data-urlencode "cuando=$(date -d "+3 days" +%Y-%m-%d)T16:30" > /dev/null
check "Reagendada" "$(date -d "+3 days" +%Y-%m-%d) 16:30:00" "$(sql "SELECT programada_para FROM actividades WHERE id=$ACT")"
post_desde "r=seguimiento" "r=seguimiento&accion=hecha" --data-urlencode "actividad_id=$ACT" > /dev/null
check "Marcada como hecha" "t" "$(sql "SELECT hecha FROM actividades WHERE id=$ACT")"
check "Solo lo mío" "200" "$(get "r=seguimiento&mias=1")"

echo "8. Clientes"
check "Lista de clientes" "200" "$(get "r=clientes")"
contiene "Ana en la lista" "$TMP/pag.html" "Ana Torres"
get "r=clientes&q=Mora" > /dev/null
contiene "Buscar cliente" "$TMP/pag.html" "Luis Mora"
no_contiene "La búsqueda filtra" "$TMP/pag.html" "Ana Torres"
get "r=clientes&q=0991112233" > /dev/null
contiene "Buscar por celular" "$TMP/pag.html" "Ana Torres"
r=$(post_desde "r=clientes" "r=clientes" --data-urlencode "nombre=Rosa Gil" --data-urlencode "telefono=0971234567")
CLI_R=$(sql "SELECT id FROM clientes WHERE negocio_id=$NID AND nombre='Rosa Gil'")
check "Crear cliente lleva a su ficha" "302 $BASE/?r=cliente&id=$CLI_R" "$r"
r=$(post_desde "r=clientes" "r=clientes" --data-urlencode "nombre=Rosa G." --data-urlencode "telefono=+593 97 123 4567")
check "Mismo celular: abre la ficha existente" "302 $BASE/?r=cliente&id=$CLI_R" "$r"
CLI_A=$(sql "SELECT cliente_id FROM eventos WHERE id=$EV1")
check "Ficha del cliente" "200" "$(get "r=cliente&id=$CLI_A")"
contiene "Sus eventos" "$TMP/pag.html" "Boda de Ana y Luis"
contiene "Total contratado" "$TMP/pag.html" "Contratado"
contiene "Seguimiento del cliente" "$TMP/pag.html" "Llamé para confirmar"
post_desde "r=cliente&id=$CLI_A" "r=cliente&id=$CLI_A&accion=guardar" --data-urlencode "nombre=Ana Torres Vega" --data-urlencode "telefono=0991112233" \
     --data-urlencode "email=ana@correo.ec" --data-urlencode "direccion=Cumbayá" --data-urlencode "origen=referido" --data-urlencode "notas=Muy puntual" > /dev/null
check "Ficha editada" "Ana Torres Vega|ana@correo.ec|referido" "$(sql "SELECT nombre||'|'||email||'|'||origen FROM clientes WHERE id=$CLI_A")"
r=$(post_desde "r=cliente&id=$CLI_A" "r=cliente&id=$CLI_A&accion=guardar" --data-urlencode "nombre=Ana" --data-urlencode "email=malo")
contiene "Error en la ficha" "$TMP/post.html" "correo del cliente no es válido"

echo "9. Espacios y fechas cerradas"
check "Pantalla de espacios" "200" "$(get "r=espacios")"
contiene "Lista los espacios" "$TMP/pag.html" "Jardín"
check "Editar espacio" "200" "$(get "r=espacios&editar=$JARDIN")"
FOTO="$TMP/foto_jardin.jpg"
php -r '$i=imagecreatetruecolor(320,200); imagefill($i,0,0,imagecolorallocate($i,60,140,90)); imagejpeg($i,$argv[1]);' "$FOTO"
get "r=espacios&editar=$JARDIN" > /dev/null; T=$(csrf)
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" -F "csrf=$T" -F "id=$JARDIN" -F "nombre=Jardín de los Arrayanes" \
     -F "capacidad_banquete=220" -F "capacidad_coctel=320" -F "precio_base=1300" -F "color=#3E7C59" -F "orden=2" -F "descripcion=Ceremonias al aire libre" \
     -F "foto=@$FOTO;type=image/jpeg" "$BASE/?r=espacios&accion=guardar")
check "Guardar espacio con foto" "302 $BASE/?r=espacios" "$r"
check "Espacio editado" "Jardín de los Arrayanes|220" "$(sql "SELECT nombre||'|'||capacidad_banquete FROM espacios WHERE id=$JARDIN")"
check "Tiene foto" "1" "$(sql "SELECT count(*) FROM espacios WHERE id=$JARDIN AND foto ~ '^[a-f0-9]{32}\.jpg$'")"
check "Foto privada" "200" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/?r=archivo&tipo=espacio&id=$JARDIN")"
check "Foto pública" "200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=imagen_publica&tipo=espacio&id=$JARDIN")"
r=$(post_desde "r=espacios" "r=espacios&accion=guardar" --data-urlencode "nombre=Terraza" --data-urlencode "capacidad_banquete=60")
check "Plan Quinta: crea un tercer espacio" "Terraza" "$(sql "SELECT nombre FROM espacios WHERE negocio_id=$NID AND nombre='Terraza'")"
r=$(post_desde "r=espacios" "r=espacios&accion=guardar" --data-urlencode "nombre=Terraza")
contiene "Nombre repetido" "$TMP/post.html" "Ya tienes un espacio llamado"
FC=$(date -d "+30 days" +%Y-%m-%d)
post_desde "r=espacios" "r=espacios&accion=cerrar" --data-urlencode "fecha=$FC" --data-urlencode "espacio_id=" --data-urlencode "motivo=Mantenimiento" > /dev/null
check "Fecha cerrada (todo el local)" "1" "$(sql "SELECT count(*) FROM fechas_cerradas WHERE negocio_id=$NID AND fecha='$FC' AND espacio_id IS NULL")"
get "r=espacios" > /dev/null
contiene "Se ve en la lista" "$TMP/pag.html" "Mantenimiento"
get "r=calendario&a=$(date -d "$FC" +%Y)&m=$(date -d "$FC" +%-m)" > /dev/null
contiene "Calendario muestra el día cerrado" "$TMP/pag.html" "ev-cerrado"
post_desde "r=espacios" "r=espacios&accion=cerrar" --data-urlencode "fecha=$FUT" --data-urlencode "espacio_id=$SALON" > /dev/null
get "r=espacios" > /dev/null
contiene "Avisa del evento afectado" "$TMP/pag.html" "ya hay 1 evento"
CER=$(sql "SELECT id FROM fechas_cerradas WHERE negocio_id=$NID AND fecha='$FUT'")
post_desde "r=espacios" "r=espacios&accion=abrir" --data-urlencode "cerrada_id=$CER" > /dev/null
check "Abrir la fecha otra vez" "0" "$(sql "SELECT count(*) FROM fechas_cerradas WHERE id=$CER")"
TERRAZA=$(sql "SELECT id FROM espacios WHERE negocio_id=$NID AND nombre='Terraza'")
post_desde "r=espacios" "r=espacios&accion=desactivar" --data-urlencode "id=$TERRAZA" > /dev/null
check "Desactivar espacio" "f" "$(sql "SELECT activo FROM espacios WHERE id=$TERRAZA")"
post_desde "r=espacios" "r=espacios&accion=desactivar" --data-urlencode "id=$SALON" > /dev/null
get "r=espacios" > /dev/null
contiene "No desactiva con eventos separados" "$TMP/pag.html" "Cámbialos de espacio antes de desactivarlo"

echo "10. Página pública, disponibilidad y consulta"
get "r=salir" > /dev/null; rm -f "$JAR"
sql "UPDATE negocios SET descripcion='La quinta más bonita del valle', instagram='@quinta.ventas', whatsapp='0987654321' WHERE id=$NID_A" > /dev/null
check "Página pública" "200" "$(get "r=sitio&s=$SLUG")"
contiene "Nombre del negocio" "$TMP/pag.html" "Quinta Ventas"
contiene "Descripción" "$TMP/pag.html" "La quinta más bonita del valle"
contiene "Espacio con foto pública" "$TMP/pag.html" "r=imagen_publica&amp;tipo=espacio&amp;id=$JARDIN"
contiene "Capacidad" "$TMP/pag.html" "220</strong> sentados"
contiene "Instagram" "$TMP/pag.html" "instagram.com/quinta.ventas"
contiene "WhatsApp del negocio" "$TMP/pag.html" "wa.me/593987654321"
contiene "Formulario de consulta" "$TMP/pag.html" 'name="sitio_web"'
no_contiene "Sí se indexa en Google" "$TMP/pag.html" 'name="robots"'
no_contiene "No muestra espacios desactivados" "$TMP/pag.html" ">Terraza<"
check "JSON público" "200" "$(get "r=disponible&s=$SLUG&fecha=$FUT")"
contiene "Separado = ocupado" "$TMP/pag.html" '"estado":"ocupado"'
no_contiene "No muestra datos de clientes" "$TMP/pag.html" "Ana"
no_contiene "Ni códigos de evento" "$TMP/pag.html" "EV-"
get "r=disponible&s=$SLUG&fecha=$FC" > /dev/null
no_contiene "Día cerrado: nada libre" "$TMP/pag.html" '"estado":"libre"'
get "r=disponible&s=$SLUG&fecha=$FUT2" > /dev/null
contiene "Con consulta = por confirmar" "$TMP/pag.html" '"estado":"consultar"'
check "Slug inexistente en JSON" "404" "$(get "r=disponible&s=no-existe-xyz&fecha=$FUT")"
check "Sin sesión, el modo interno no responde" "401" "$(get "r=disponible&fecha=$FUT&espacios[]=$SALON")"
ANTES=$(sql "SELECT count(*) FROM notificaciones WHERE negocio_id=$NID_A")
r=$(post_desde "r=sitio&s=$SLUG" "r=consulta&s=$SLUG" --data-urlencode "nombre=María Pérez" --data-urlencode "telefono=0997776655" \
     --data-urlencode "email=maria@correo.ec" --data-urlencode "tipo=quince" --data-urlencode "fecha=$FUT2" --data-urlencode "invitados=120" \
     --data-urlencode "espacio_id=$JARDIN" --data-urlencode "mensaje=Queremos ver el jardín" --data-urlencode "sitio_web=")
check "Consulta lleva a la página de gracias" "302 $BASE/?r=consulta&s=$SLUG" "$r"
EVW=$(sql "SELECT id FROM eventos WHERE negocio_id=$NID_A ORDER BY id DESC LIMIT 1")
check "Crea el evento en consulta desde la página" "consulta|pagina|quince" "$(sql "SELECT estado||'|'||origen||'|'||tipo FROM eventos WHERE id=$EVW")"
check "Con el espacio preferido" "$JARDIN" "$(sql "SELECT espacio_id FROM evento_espacios WHERE evento_id=$EVW")"
check "Crea el cliente" "pagina" "$(sql "SELECT c.origen FROM eventos e JOIN clientes c ON c.id=e.cliente_id WHERE e.id=$EVW")"
check "Actividad para responder" "1" "$(sql "SELECT count(*) FROM actividades WHERE evento_id=$EVW AND NOT hecha")"
check "Aviso al negocio" "$((ANTES+1))" "$(sql "SELECT count(*) FROM notificaciones WHERE negocio_id=$NID_A")"
check "Página de gracias" "200" "$(get "r=consulta&s=$SLUG")"
contiene "Gracias con el nombre" "$TMP/pag.html" "Gracias, María"
contiene "Botón WhatsApp al negocio" "$TMP/pag.html" "wa.me/593987654321"
r=$(post_desde "r=sitio&s=$SLUG" "r=consulta&s=$SLUG" --data-urlencode "nombre=Robot" --data-urlencode "telefono=0991111111" \
     --data-urlencode "tipo=boda" --data-urlencode "fecha=$FUT2" --data-urlencode "invitados=10" --data-urlencode "sitio_web=http://spam.com")
check "Campo trampa: vuelve a la página" "302 $BASE/?r=sitio&s=$SLUG#consulta" "$r"
check "El robot no crea evento" "$EVW" "$(sql "SELECT id FROM eventos WHERE negocio_id=$NID_A ORDER BY id DESC LIMIT 1")"
r=$(post_desde "r=sitio&s=$SLUG" "r=consulta&s=$SLUG" --data-urlencode "nombre=Sin Celular" --data-urlencode "telefono=" \
     --data-urlencode "tipo=boda" --data-urlencode "fecha=$FUT2" --data-urlencode "invitados=10")
get "r=sitio&s=$SLUG" > /dev/null
contiene "Error claro en la página" "$TMP/pag.html" "Escribe tu celular"
contiene "Conserva lo que escribió" "$TMP/pag.html" 'value="Sin Celular"'
for i in 1 2 3 4; do
  post_desde "r=sitio&s=$SLUG" "r=consulta&s=$SLUG" --data-urlencode "nombre=Persona $i" --data-urlencode "telefono=098000001$i" \
     --data-urlencode "tipo=boda" --data-urlencode "fecha=$FUT2" --data-urlencode "invitados=50" > /dev/null
done
r=$(post_desde "r=sitio&s=$SLUG" "r=consulta&s=$SLUG" --data-urlencode "nombre=Persona Seis" --data-urlencode "telefono=0980000199" \
     --data-urlencode "tipo=boda" --data-urlencode "fecha=$FUT2" --data-urlencode "invitados=50")
get "r=sitio&s=$SLUG" > /dev/null
contiene "Límite de 5 consultas por hora" "$TMP/pag.html" "Ya enviaste varias consultas"
check "Solo 5 consultas creadas" "5" "$(sql "SELECT count(*) FROM eventos WHERE negocio_id=$NID_A AND origen='pagina'")"
check "Negocio que no existe" "404" "$(get "r=sitio&s=no-existe-xyz")"
contiene "Página no disponible" "$TMP/pag.html" "no está disponible"
sql "UPDATE negocios SET estado='suspendido' WHERE id=$NID_A" > /dev/null
check "Negocio suspendido (pausado): temporalmente no disponible (503)" "503" "$(get "r=sitio&s=$SLUG")"
check "Suspendido: JSON tampoco (503)" "503" "$(get "r=disponible&s=$SLUG&fecha=$FUT")"
sql "UPDATE negocios SET estado='prueba' WHERE id=$NID_A" > /dev/null

echo "11. Permisos"
# Coordinador
sql "INSERT INTO usuarios (negocio_id, nombre, email, password_hash, rol) VALUES ($NID_A, 'Carla Coord', 'coord$NID_A@prueba.ec', '$(php -r 'echo password_hash("claveSegura1", PASSWORD_DEFAULT);')', 'coordinador')" > /dev/null
entrar "coord$NID_A@prueba.ec" "claveSegura1"
check "Coordinador ve el calendario" "200" "$(get "r=calendario")"
check "Coordinador ve el evento" "200" "$(get "r=evento&id=$EV1")"
no_contiene "Coordinador no ve el dinero" "$TMP/pag.html" "Saldo"
no_contiene "Coordinador no cambia estados" "$TMP/pag.html" "accion=estado"
check "Coordinador no puede crear eventos" "302" "$(get "r=evento_nuevo")"
check "Coordinador no ve clientes" "302" "$(get "r=clientes")"
check "Coordinador no ve seguimiento" "302" "$(get "r=seguimiento")"
r=$(post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=estado" --data-urlencode "estado=cotizado")
check "Coordinador no cambia el estado (403)" "403 " "$r"
check "El estado sigue igual" "separado" "$(sql "SELECT estado FROM eventos WHERE id=$EV1")"
check "Coordinador ve espacios (solo mirar)" "200" "$(get "r=espacios")"
no_contiene "Sin botones de editar espacios" "$TMP/pag.html" "accion=desactivar"
# Consulta (solo ver)
sql "INSERT INTO usuarios (negocio_id, nombre, email, password_hash, rol) VALUES ($NID_A, 'Contador', 'consulta$NID_A@prueba.ec', '$(php -r 'echo password_hash("claveSegura1", PASSWORD_DEFAULT);')', 'consulta')" > /dev/null
entrar "consulta$NID_A@prueba.ec" "claveSegura1"
check "Consulta ve el evento" "200" "$(get "r=evento&id=$EV1")"
contiene "Consulta ve el dinero" "$TMP/pag.html" "Dinero"
contiene "Consulta: aviso de solo ver" "$TMP/pag.html" "solo puede ver"
check "Consulta no crea eventos" "403" "$(get "r=evento_nuevo")"
r=$(post_desde "r=evento&id=$EV1" "r=evento&id=$EV1&accion=tarea" --data-urlencode "titulo=Intento")
check "Consulta no agrega tareas" "403 " "$r"
# Otro negocio
registrar quinta "Otro Salón $RANDOM"
check "Otro negocio no ve el evento" "404" "$(get "r=evento&id=$EV1")"
no_contiene "No se filtra el nombre" "$TMP/pag.html" "Ana y Luis"
check "Otro negocio no edita el evento" "404" "$(get "r=evento_editar&id=$EV1")"
check "Otro negocio no ve el cliente" "404" "$(get "r=cliente&id=$CLI_A")"
r=$(post_desde "r=calendario" "r=evento&id=$EV1&accion=estado" --data-urlencode "estado=cotizado")
check "Otro negocio no cambia el estado" "separado" "$(sql "SELECT estado FROM eventos WHERE id=$EV1")"
get "r=calendario&a=$ANIO&m=$MES" > /dev/null
no_contiene "Su calendario no muestra mis eventos" "$TMP/pag.html" "Ana y Luis"
get "r=disponible&fecha=$FUT&espacios[]=$SALON" > /dev/null
contiene "Con mis espacios en su consulta: sin choques" "$TMP/pag.html" '"choques":\[\]'
r=$(post_desde "r=espacios" "r=espacios&accion=guardar" --data-urlencode "id=$JARDIN" --data-urlencode "nombre=Robado")
check "No edita espacios de otro" "Jardín de los Arrayanes" "$(sql "SELECT nombre FROM espacios WHERE id=$JARDIN")"
check "No ve la foto privada de otro" "404" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/?r=archivo&tipo=espacio&id=$JARDIN")"

echo "12. Plan Salón: límite de espacios"
registrar salon "Salón Chico $RANDOM"
get "r=espacios" > /dev/null
contiene "Explica el límite" "$TMP/pag.html" "permite 2 espacios activos"
no_contiene "Sin botón de nuevo espacio" "$TMP/pag.html" "nuevo=1"
r=$(post_desde "r=espacios" "r=espacios&accion=guardar" --data-urlencode "nombre=Terraza")
contiene "Mensaje para cambiar de plan" "$TMP/post.html" "cambia de plan en Mi plan"
check "No se creó" "2" "$(sql "SELECT count(*) FROM espacios WHERE negocio_id=$NID")"
check "Calendario vacío también abre" "200" "$(get "r=calendario")"
check "Lista vacía" "200" "$(get "r=eventos")"
contiene "Estado vacío útil" "$TMP/pag.html" "Todavía no tienes eventos"
check "Seguimiento vacío" "200" "$(get "r=seguimiento")"
contiene "Todo al día" "$TMP/pag.html" "Todo al día"
check "Tablero vacío" "200" "$(get "r=eventos&vista=tablero")"
check "Clientes vacío" "200" "$(get "r=clientes")"
