# Planes de $20 y $45 en pantalla (migración 112, decisión de Dimitry del 28 oct 2026): Mi plan del Básico con 3 módulos a elegir
# («Tu plan trae 3 módulos a tu elección»), elegidos «Incluido», los demás «Extra · $5», sin precios de lo que no tiene;
# Mi plan del Completo; Planes y precios del súper usuario (módulos a elegir y precio de cada uno más) y la ficha del negocio.
planes_20_45
DBENV="TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD"

echo "1. Básico pagado con 4 módulos y el Asistente IA: 3 incluidos, el cuarto \$5 y el Asistente IA \$5"
registrar esencial "Local Básico Veinte $RANDOM"; pagar_plan esencial; NB=$NID; EB=$EMAIL
sql "UPDATE negocios SET modulos_extra='paquetes,contratos,portal_cliente,plano,asistente_ia' WHERE id=$NB" > /dev/null
check "Mi plan abre" "200" "$(get "r=planes")"
contiene "Plan Básico" "$TMP/pag.html" "Plan <strong>Básico</strong>"
contiene "Dice cuántos módulos trae a su elección" "$TMP/pag.html" "Tu plan trae <strong>3 módulos a tu elección</strong>"
# Aceptar los términos (módulo común TukanLegal, 7 nov 2026)
get "r=inicio" > /dev/null
contiene "El dueño ve el aviso para aceptar los términos y la privacidad" "$TMP/pag.html" 'value="aceptar_terminos_tukan"'
contiene "… y el menú los enlaces Términos · Privacidad" "$TMP/pag.html" 'class="tukan-legal-enlaces"'
post "r=planes" -d accion=aceptar_terminos_tukan > /dev/null
check "Sin marcar la casilla no se acepta" "0" "$(sql "SELECT count(*) FROM tukan_aceptaciones WHERE cliente_id='$NB'")"
post "r=planes" -d accion=aceptar_terminos_tukan -d acepto_terminos=1 > /dev/null
check "Aceptar: queda guardado con la versión" "2026-10-27" "$(sql "SELECT version FROM tukan_aceptaciones WHERE cliente_id='$NB'")"
get "r=inicio" > /dev/null
no_contiene "Ya no sale el aviso" "$TMP/pag.html" 'value="aceptar_terminos_tukan"'
get "r=planes" > /dev/null
# «Cancelar mi plan» (módulo común, 7 nov 2026)
contiene "Mi plan trae «Cancelar mi plan» con la casilla de confirmar" "$TMP/pag.html" 'name="confirmo" value="1" required'
post "r=planes" -d accion=cancelar_plan -d motivo=x > /dev/null
check "Sin la casilla no cancela" "0" "$(sql "SELECT count(*) FROM tukan_cancelaciones WHERE cliente_id='$NB'")"
post "r=planes" -d accion=cancelar_plan -d confirmo=1 --data-urlencode "motivo=Ya no lo uso" > /dev/null
check "Cancelar: queda guardada con el motivo" "Ya no lo uso" "$(sql "SELECT motivo FROM tukan_cancelaciones WHERE cliente_id='$NB'")"
get "r=planes" > /dev/null
contiene "… y ofrece «Seguir con mi plan»" "$TMP/pag.html" 'value="seguir_plan"'
post "r=planes" -d accion=seguir_plan > /dev/null
check "Seguir con mi plan: se quita" "0" "$(sql "SELECT count(*) FROM tukan_cancelaciones WHERE cliente_id='$NB'")"
get "r=planes" > /dev/null
contiene "… sin módulos por elegir" "$TMP/pag.html" 'data-por-elegir="0"'
contiene "Los elegidos dicen «Incluido»" "$TMP/pag.html" "Incluido · lo elegiste"
contiene "El cuarto módulo y el Asistente IA: «Extra · \$5»" "$TMP/pag.html" "Extra · \$5,00 + IVA al mes"
contiene "Total: \$20 + \$5 + \$5 = \$30" "$TMP/pag.html" '<p class="mp-precio">\$30,00'
contiene "Desglose: plan \$20" "$TMP/pag.html" "Plan \$20,00"
no_contiene "Sin el precio de un módulo que no tiene (Invitados \$8)" "$TMP/pag.html" "\$8,00"
no_contiene "Sin el precio del Completo" "$TMP/pag.html" "\$45,00"
contiene "El Completo: todos los módulos con el Asistente IA, precio por cotización" "$TMP/pag.html" "Todos los módulos, con el Asistente IA · precio por cotización"

echo "2. Básico con 1 elegido: le quedan 2; los demás «Puedes elegirlo en tu plan» y se eligen desde Mi plan"
sql "UPDATE negocios SET modulos_extra='paquetes' WHERE id=$NB" > /dev/null
get "r=planes" > /dev/null
contiene "Le quedan 2 por elegir" "$TMP/pag.html" 'data-por-elegir="2"'
contiene "… y lo dice" "$TMP/pag.html" "te quedan 2 por elegir"
contiene "Los que no tiene: «Puedes elegirlo en tu plan»" "$TMP/pag.html" "Puedes elegirlo en tu plan"
contiene "… con el botón «Elegir este módulo»" "$TMP/pag.html" "Elegir este módulo"
contiene "El Asistente IA no se elige entre los 3: se pide aparte" "$TMP/pag.html" "Pídalo y le cotizamos"
no_contiene "Sin «Extra · \$» (no paga extras)" "$TMP/pag.html" "Extra · \$"
r=$(post "r=planes" --data-urlencode "accion=pedir" --data-urlencode "modulo=invitados")
check "Elegir Invitados: pedido para Tukán" "1" "$(sql "SELECT count(*) FROM modulos_solicitudes WHERE negocio_id=$NB AND encender='invitados' AND estado='pendiente' AND nota LIKE 'Lo elige entre los módulos incluidos%'")"
get "r=planes" > /dev/null
contiene "Aviso: lo eligió entre los de su plan" "$TMP/pag.html" "elegiste Invitados con QR entre los módulos de tu plan"
sql "UPDATE negocios SET modulos_extra='paquetes,contratos,portal_cliente' WHERE id=$NB" > /dev/null
get "r=planes" > /dev/null
contiene "Con los 3 elegidos: ya no ofrece elegir" "$TMP/pag.html" 'data-por-elegir="0"'
no_contiene "… ni «Puedes elegirlo en tu plan»" "$TMP/pag.html" "Puedes elegirlo en tu plan"
contiene "… los demás: «Precio por cotización» (sin precio)" "$TMP/pag.html" "Precio por cotización"

echo "3. Completo pagado: \$45 con todo y el Asistente IA"
registrar quinta "Quinta Completo $RANDOM"; pagar_plan quinta; NC=$NID
get "r=planes" > /dev/null
contiene "Plan Completo" "$TMP/pag.html" "Plan <strong>Completo</strong>"
contiene "\$45 al mes" "$TMP/pag.html" '<p class="mp-precio">\$45,00'
no_contiene "Sin «módulos a tu elección»" "$TMP/pag.html" "a tu elección</strong>"
contiene "El Asistente IA: incluido en su plan" "$TMP/pag.html" "Incluido en tu plan"
contiene "El Básico, sin precio" "$TMP/pag.html" "La base + 3 módulos que tú eliges · precio por cotización"
no_contiene "… ni \$20" "$TMP/pag.html" "\$20,00"

echo "4. Panel Tukán: Planes y precios con módulos a elegir; ficha del negocio"
ADM="super112$RANDOM@tukan.ec"
env $DBENV TURENTA_ADMIN_NOMBRE="Dimitry Tukán" TURENTA_ADMIN_EMAIL="$ADM" TURENTA_ADMIN_CLAVE="ClaveDelPanel123" php bin/crear_admin.php > /dev/null 2>&1
rm -f "$JAR"
post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=ClaveDelPanel123" > /dev/null
check "Planes y precios abre" "200" "$(get "r=admin_planes")"
contiene "Campo «Módulos que elige el cliente»" "$TMP/pag.html" 'name="modulos_elegir" value="3"'
contiene "… y «Cada módulo más»" "$TMP/pag.html" 'name="precio_modulo_extra" value="5.00"'
contiene "Explica que el Asistente IA no cuenta entre los que elige" "$TMP/pag.html" "El Asistente IA no cuenta entre los que elige"
r=$(post_desde "r=admin_planes" "r=admin_planes" -d accion=plan -d codigo=esencial --data-urlencode "nombre=Básico" -d precio_mensual=20 -d max_espacios=1 -d max_usuarios=2 \
     --data-urlencode "descripcion=La base de TuRenta + 4 módulos que tú eliges." -d modulos_elegir=4 -d precio_modulo_extra=4)
check "Guardar 4 módulos a elegir a \$4 cada uno más" "4|4.00" "$(sql "SELECT modulos_elegir||'|'||precio_modulo_extra FROM planes WHERE codigo='esencial'")"
check "… queda en el registro del panel" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE accion LIKE 'Plan esencial actualizado:%módulos a elegir 4%'")"
r=$(post_desde "r=admin_planes" "r=admin_planes" -d accion=plan -d codigo=esencial --data-urlencode "nombre=Básico" -d precio_mensual=20 -d max_espacios=1 -d max_usuarios=2 \
     --data-urlencode "descripcion=x" -d modulos_elegir=dos -d precio_modulo_extra=4)
check "Módulos a elegir con letras: no se guarda" "4" "$(sql "SELECT modulos_elegir FROM planes WHERE codigo='esencial'")"
post_desde "r=admin_planes" "r=admin_planes" -d accion=plan -d codigo=esencial --data-urlencode "nombre=Básico" -d precio_mensual=20 -d max_espacios=1 -d max_usuarios=2 \
     --data-urlencode "descripcion=La base de TuRenta + 3 módulos que tú eliges." -d modulos_elegir=3 -d precio_modulo_extra=5 > /dev/null
check "… y vuelve a 3 a \$5" "3|5.00" "$(sql "SELECT modulos_elegir||'|'||precio_modulo_extra FROM planes WHERE codigo='esencial'")"
sql "UPDATE negocios SET modulos_extra='paquetes,contratos,portal_cliente,plano' WHERE id=$NB" > /dev/null
check "Ficha del negocio Básico" "200" "$(get "r=admin_negocio&id=$NB")"
contiene "Los elegidos: «Elegido · incluido en el plan»" "$TMP/pag.html" "Elegido · incluido en el plan"
contiene "El cuarto: «Extra · +\$5»" "$TMP/pag.html" "Extra · +\$5,00"
contiene "Cuenta: 3 de 3 módulos a elegir" "$TMP/pag.html" "3 de 3"
contiene "Paga \$25 al mes" "$TMP/pag.html" "Paga <strong>\$25,00</strong>"
