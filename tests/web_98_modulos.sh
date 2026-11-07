# Programa modular (Anexo B) en pantalla: Mi plan, pedir un módulo, página de módulo apagado, menús y pestañas,
# portales públicos "no disponible", PDF, y en el Panel Tukán: interruptores por negocio, aprobar el pedido, catálogo y pedidos de demo.
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh
DBENV="TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD"

echo "1. Demo: todo encendido; Mi plan lo explica"
registrar quinta "Quinta Modular $RANDOM"; NM=$NID; EM=$EMAIL
check "Mi plan abre" "200" "$(get "r=planes")"
contiene "Dice que está en demo con todo" "$TMP/pag.html" "tienes todos los módulos encendidos"
contiene "Lista los módulos" "$TMP/pag.html" "Invitados con QR"
contiene "La base siempre incluida" "$TMP/pag.html" "Siempre incluido"
no_contiene "Mi plan no ofrece nada gratis" "$TMP/pag.html" "gratis"
check "Día del evento en demo: abre" "200" "$(get "r=mobiliario")"

echo "2. Plan Salón pagado: lo de Quinta apagado (menús, páginas, portales, PDF)"
pagar_plan salon
get "r=planes" > /dev/null
contiene "Precio del plan" "$TMP/pag.html" "\$39,00"
contiene "Mobiliario: no está en tu plan" "$TMP/pag.html" "No está en tu plan"
contiene "Botón para pedir cotización" "$TMP/pag.html" "Pedir cotización"
no_contiene "Sin el precio del módulo que no tiene (regla del dueño)" "$TMP/pag.html" "+\$5,00 al mes"
get "r=mas" > /dev/null
no_contiene "Más: sin Mobiliario" "$TMP/pag.html" "r=mobiliario"
no_contiene "Más: sin Planos" "$TMP/pag.html" "r=plano\""
contiene "Más: con Proveedores (está en Salón)" "$TMP/pag.html" "r=proveedores"
check "Mobiliario apagado: 403" "403" "$(get "r=mobiliario")"
contiene "Página amable" "$TMP/pag.html" "Este módulo no está en tu plan"
no_contiene "… sin el precio (se pide cotización)" "$TMP/pag.html" "\$5,00"
contiene "… con Pedir cotización" "$TMP/pag.html" "Pedir cotización"
contiene "… y el enlace a Mi plan" "$TMP/pag.html" "r=planes#mod-mobiliario"
contiene "… dice que los datos se guardan" "$TMP/pag.html" "tus datos siguen guardados"
r=$(post_desde "r=inicio" "r=mobiliario" --data-urlencode "accion=guardar" --data-urlencode "nombre=Sillas")
check "Tampoco se puede guardar por POST" "403" "${r%% *}"
EVM=$(crear_evento_sql "$(date -d '+20 days' +%F)" separado)
TOKM=$(sql "SELECT token_cliente FROM eventos WHERE id=$EVM")
check "Portal del cliente (está en Salón): abre" "200" "$(get "r=portal&t=$TOKM")"
no_contiene "… sin invitados (módulo apagado)" "$TMP/pag.html" "Tus invitados"
check "Portal de invitados público: no disponible" "403" "$(get "r=portal_invitados&t=$TOKM")"
contiene "… No disponible" "$TMP/pag.html" "No disponible"
TVM=$(sql "SELECT token_vivo FROM eventos WHERE id=$EVM")
check "Cronograma en vivo público: no disponible" "403" "$(get "r=vivo&t=$TVM")"
check "PDF de invitados: no sale" "0" "$(curl -s -b "$JAR" "$BASE/?r=pdf&tipo=invitados&id=$EVM" | head -c 4 | grep -c '%PDF')"
check "PDF que no existe: 404" "404" "$(get "r=pdf&tipo=nada&id=$EVM")"

echo "3. Pedir un módulo desde Mi plan → solicitud"
r=$(post_desde "r=planes" "r=planes" --data-urlencode "accion=pedir" --data-urlencode "modulo=mobiliario")
check "Pedir: vuelve a Mi plan" "302" "${r%% *}"
check "Pedido guardado pendiente" "pendiente|mobiliario" "$(sql "SELECT estado||'|'||encender FROM modulos_solicitudes WHERE negocio_id=$NM")"
get "r=planes" > /dev/null
contiene "Mi plan: Pedido enviado" "$TMP/pag.html" "Pedido enviado"
contiene "Mi plan: tus pedidos" "$TMP/pag.html" "Tus pedidos de módulos"
post_desde "r=planes" "r=planes" --data-urlencode "accion=pedir" --data-urlencode "modulo=mobiliario" > /dev/null
check "Pedir otra vez: no se repite" "1" "$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NM")"
get "r=mobiliario" > /dev/null
contiene "La página del módulo dice que ya lo pidió" "$TMP/pag.html" "Ya pediste este módulo"
post_desde "r=planes" "r=planes" --data-urlencode "accion=pedir" --data-urlencode "modulo=contratos" > /dev/null
check "Pedir uno que ya tiene: no crea pedido" "1" "$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NM")"
# Un vendedor no ve Mi plan
sql "INSERT INTO usuarios (negocio_id, nombre, email, password_hash, rol) VALUES ($NM, 'Vende', 'vende$NM@prueba.ec', '$(php -r 'echo password_hash("claveSegura1", PASSWORD_DEFAULT);')', 'vendedor')" > /dev/null
entrar "vende$NM@prueba.ec" claveSegura1
check "Vendedor: Mi plan no (solo el dueño)" "403" "$(get "r=planes")"
get "r=mobiliario" > /dev/null
contiene "Vendedor: le pide al dueño" "$TMP/pag.html" "Pídele al dueño"

echo "4. Panel Tukán: módulos del negocio, aprobar el pedido, catálogo"
ADM="admmod$RANDOM@tukan.ec"
env $DBENV TURENTA_ADMIN_NOMBRE="Dimitry Tukán" TURENTA_ADMIN_EMAIL="$ADM" TURENTA_ADMIN_CLAVE="ClaveDelPanel123" php bin/crear_admin.php > /dev/null 2>&1
rm -f "$JAR"
post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=ClaveDelPanel123" > /dev/null
check "Ficha del negocio" "200" "$(get "r=admin_negocio&id=$NM")"
contiene "Sección Módulos" "$TMP/pag.html" 'id="modulos"'
contiene "Interruptores" "$TMP/pag.html" 'class="interruptor"'
contiene "Total al mes" "$TMP/pag.html" "Paga <strong>\$39,00</strong> al mes"
contiene "Pedido desde Mi plan" "$TMP/pag.html" "Pidió desde Mi plan"
SID=$(sql "SELECT id FROM modulos_solicitudes WHERE negocio_id=$NM")
r=$(post_desde "r=admin_negocio&id=$NM" "r=admin_negocio&id=$NM&accion=solicitud_modulos" --data-urlencode "solicitud=$SID" --data-urlencode "resultado=aprobada")
check "Aprobar el pedido" "aprobada|mobiliario" "$(sql "SELECT s.estado||'|'||n.modulos_extra FROM modulos_solicitudes s JOIN negocios n ON n.id=s.negocio_id WHERE s.id=$SID")"
get "r=admin_negocio&id=$NM" > /dev/null
contiene "Ahora paga plan + mobiliario" "$TMP/pag.html" "Paga <strong>\$44,00</strong> al mes"
# Guardar interruptores: todo lo de Salón + mobiliario + invitados, sin metas, con precio acordado
ARGS=()
for m in paquetes contratos portal_cliente cobros_linea cuestionarios proveedores incidencias telegram exportar mobiliario invitados; do ARGS+=(--data-urlencode "modulo[$m]=1"); done
r=$(post_desde "r=admin_negocio&id=$NM" "r=admin_negocio&id=$NM&accion=modulos" "${ARGS[@]}" --data-urlencode "precio_acordado=")
check "Guardar módulos: extra y quitados" "invitados,mobiliario|metas" "$(sql "SELECT modulos_extra||'|'||modulos_quitados FROM negocios WHERE id=$NM")"
check "Queda en el registro del panel" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE negocio_id=$NM AND accion LIKE 'Módulos: Encendidos: Invitados%'")"
r=$(post_desde "r=admin_negocio&id=$NM" "r=admin_negocio&id=$NM&accion=modulos" "${ARGS[@]}" --data-urlencode "modulo[boda_vivo]=1" --data-urlencode "precio_acordado=50")
check "Encender boda en vivo enciende el día del evento; precio acordado" "dia_evento,invitados,mobiliario,boda_vivo|50.00" \
      "$(sql "SELECT modulos_extra||'|'||precio_acordado FROM negocios WHERE id=$NM")"
r=$(post_desde "r=admin_negocio&id=$NM" "r=admin_negocio&id=$NM&accion=modulos" "${ARGS[@]}" --data-urlencode "modulo[boda_vivo]=1" --data-urlencode "precio_acordado=")
get "r=admin_negocio&id=$NM" > /dev/null
contiene "No se apaga el día del evento si boda en vivo lo necesita" "$TMP/pag.html" "lo necesita Boda en vivo"
check "Catálogo y planes" "200" "$(get "r=admin_planes")"
contiene "Catálogo de módulos" "$TMP/pag.html" "Catálogo de módulos"
contiene "Qué trae cada plan" "$TMP/pag.html" "Qué módulos trae cada plan"
CARGS=()
while IFS='|' read -r mid precio orden; do CARGS+=(--data-urlencode "precio[$mid]=$precio" --data-urlencode "orden[$mid]=$orden" --data-urlencode "descripcion[$mid]="); done \
  < <(sql "SELECT id||'|'||precio_mensual||'|'||orden FROM modulos")
CARGS+=(--data-urlencode "plan_todos[quinta]=1" --data-urlencode "plan_mod[quinta][asistente_ia]=1")   # como el navegador: la casilla del Asistente IA del plan más caro viene marcada
for m in paquetes contratos portal_cliente cobros_linea cuestionarios proveedores incidencias metas telegram exportar; do CARGS+=(--data-urlencode "plan_mod[salon][$m]=1"); done
r=$(post_desde "r=admin_planes" "r=admin_planes" --data-urlencode "accion=catalogo" "${CARGS[@]/precio\[plano\]=6.00/precio[plano]=7.50}")
check "Guardar catálogo: precio nuevo del plano" "7.50" "$(sql "SELECT precio_mensual FROM modulos WHERE id='plano'")"
check "… Salón sigue con sus 10 módulos y Quinta con todos + el Asistente IA incluido" "10|*,asistente_ia" "$(sql "SELECT (SELECT array_length(string_to_array(modulos, ','), 1) FROM planes WHERE codigo='salon')||'|'||(SELECT modulos FROM planes WHERE codigo='quinta')")"
sql "UPDATE modulos SET precio_mensual = 6 WHERE id='plano'" > /dev/null

echo "5. Panel Tukán: pedidos de demo → crear demo"
rm -f "$JAR"
post "r=registro" --data-urlencode "negocio=Quinta Pedido Panel $RANDOM" --data-urlencode "nombre=Rita Panel" --data-urlencode "whatsapp=0991230000" \
     --data-urlencode "email=rita$RANDOM@panel.ec" --data-urlencode "modulos[]=plano" > /dev/null
PDP=$(sql "SELECT id FROM pedidos_demo ORDER BY id DESC LIMIT 1")
post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=ClaveDelPanel123" > /dev/null
get "r=admin" > /dev/null
contiene "Resumen: pedidos de demo" "$TMP/pag.html" "Pedidos de demo"
contiene "… con el botón Crear demo" "$TMP/pag.html" "r=admin_nuevo&amp;pedido=$PDP"
get "r=admin_nuevo&pedido=$PDP" > /dev/null
contiene "Nueva demo con los datos del pedido" "$TMP/pag.html" "Rita Panel"
r=$(post_desde "r=admin_nuevo&pedido=$PDP" "r=admin_nuevo" --data-urlencode "pedido=$PDP" --data-urlencode "negocio=Quinta Pedido Panel" --data-urlencode "nombre=Rita Panel" \
     --data-urlencode "telefono=0991230000" --data-urlencode "email=rita.demo$RANDOM@panel.ec" --data-urlencode "ciudad=Quito" --data-urlencode "plan=salon")
check "Demo creada: el pedido queda unido" "demo_creada|demo_pedida|prueba" \
      "$(sql "SELECT p.estado||'|'||n.origen||'|'||n.estado FROM pedidos_demo p JOIN negocios n ON n.id=p.negocio_id WHERE p.id=$PDP")"

echo "6. Regla del dueño: en las pantallas del cliente no sale el precio del software que no tiene, ni la palabra gratis"
registrar salon "Salon Sin Precios $RANDOM"; NP=$NID
pagar_plan salon
check "Mi plan abre (Salón pagado)" "200" "$(get "r=planes")"
contiene "Su propio plan sí se ve (es su contrato)" "$TMP/pag.html" "\$39,00"
contiene "Módulo que no tiene: precio por cotización" "$TMP/pag.html" "Precio por cotización"
contiene "… con el botón Pedir cotización" "$TMP/pag.html" "Pedir cotización"
no_contiene "Ya no dice Pedir este módulo" "$TMP/pag.html" "Pedir este módulo"
# Cada módulo apagado y cada otro plan: su precio no aparece en Mi plan
while IFS='|' read -r mid precio; do
  p=$(printf '%s' "$precio" | sed 's/\./,/')
  no_contiene "Sin precio del módulo $mid (\$$p)" "$TMP/pag.html" "+\$$p al mes"
done < <(sql "SELECT id||'|'||to_char(precio_mensual,'FM999990.00') FROM modulos WHERE NOT (id = ANY (string_to_array((SELECT modulos FROM planes WHERE codigo='salon'), ',')))")
while IFS='|' read -r cod precio; do
  p=$(printf '%s' "$precio" | sed 's/\./,/')
  no_contiene "Sin precio del plan $cod (\$$p)" "$TMP/pag.html" "\$$p"
done < <(sql "SELECT codigo||'|'||to_char(precio_mensual,'FM999990.00') FROM planes WHERE codigo <> 'salon'")
check "Módulo apagado: página amable" "403" "$(get "r=mobiliario")"
no_contiene "… sin «Agrégalo por»" "$TMP/pag.html" "Agrégalo por"
no_contiene "… sin el precio del módulo" "$TMP/pag.html" "\$$(sql "SELECT to_char(precio_mensual,'FM999990.00') FROM modulos WHERE id='mobiliario'" | sed 's/\./,/')"
contiene "… con Pedir cotización" "$TMP/pag.html" "Pedir cotización"
r=$(post_desde "r=mobiliario" "r=planes" --data-urlencode "accion=pedir" --data-urlencode "modulo=mobiliario")
check "Pedir cotización desde la página del módulo → solicitud al Panel Central" "302|pendiente|mobiliario" "${r%% *}|$(sql "SELECT estado||'|'||encender FROM modulos_solicitudes WHERE negocio_id=$NP")"
get "r=planes" > /dev/null
contiene "Otro plan: Pedir cotización" "$TMP/pag.html" 'name="accion" value="pedir_plan"'
r=$(post_desde "r=planes" "r=planes" --data-urlencode "accion=pedir_plan" --data-urlencode "plan=quinta")
check "Pedir cotización del plan Quinta → pedido con sus módulos" "302|1" \
      "${r%% *}|$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NP AND nota LIKE 'Pide cotización del plan Quinta%' AND estado='pendiente'")"
r=$(post_desde "r=planes" "r=planes" --data-urlencode "accion=pedir_plan" --data-urlencode "plan=salon")
check "Pedir cotización de su mismo plan: no crea pedido" "2" "$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NP")"
for ruta in planes inicio mas mobiliario mi_cuenta; do
  get "r=$ruta" > /dev/null
  no_contiene "Sin «gratis» en $ruta" "$TMP/pag.html" "[Gg]ratis"
  no_contiene "Sin «free» en $ruta" "$TMP/pag.html" "[^a-z-][Ff]ree[^a-z-]"
done
rm -f "$JAR"
for ruta in login registro; do
  get "r=$ruta" > /dev/null
  no_contiene "Sin «gratis» en $ruta (público)" "$TMP/pag.html" "[Gg]ratis"
done
