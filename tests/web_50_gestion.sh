# Gestión: inicio, reportes y exportar, configuración, usuarios, mi cuenta, avisos, planes, iCal, Telegram, panel Tukán
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh
HOY=$(date +%Y-%m-%d)
EN3=$(date -d "+3 days" +%Y-%m-%d)
EN40=$(date -d "+40 days" +%Y-%m-%d)
DBENV="TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD"

echo "1. Inicio de una cuenta nueva"
registrar quinta "Quinta Gestión $RANDOM"
NID_G=$NID; EMAIL_G=$EMAIL
check "Inicio responde" "200" "$(get "r=inicio")"
contiene "Saludo" "$TMP/pag.html" "Dimitry"
contiene "Primeros pasos" "$TMP/pag.html" "Primeros pasos"
contiene "Paso: datos del negocio" "$TMP/pag.html" "Completa los datos de tu negocio"
contiene "Paso: Telegram" "$TMP/pag.html" "Conecta tu Telegram"
contiene "Acceso rápido a nuevo evento" "$TMP/pag.html" "r=evento_nuevo"
contiene "Caja para instalar la app" "$TMP/pag.html" "data-instalar-app"
contiene "Botón instalar" "$TMP/pag.html" "data-instalar"
contiene "Cifras: vencido por cobrar" "$TMP/pag.html" "Vencido por cobrar"
contiene "Estilos del módulo" "$TMP/pag.html" "mod_gestion.css"

echo "2. Inicio con datos"
EV_A=$(crear_evento_sql "$EN3" separado)
sql "UPDATE eventos SET total = 2000, nombre = 'Boda de Ana y Luis' WHERE id = $EV_A" > /dev/null
sql "INSERT INTO cuotas (evento_id, numero, concepto, vence, monto) VALUES ($EV_A, 1, 'Saldo', '$HOY'::date - 2, 1500)" > /dev/null
EV_C=$(crear_evento_sql "$EN40" consulta)
sql "UPDATE eventos SET nombre = 'Quince de Sofía' WHERE id = $EV_C" > /dev/null
EV_R=$(crear_evento_sql "$(date -d "+60 days" +%Y-%m-%d)" cotizado)
sql "UPDATE eventos SET retenida_hasta = '$HOY'::date + 1, nombre = 'Boda Retenida' WHERE id = $EV_R" > /dev/null
UID_G=$(sql "SELECT id FROM usuarios WHERE email='$EMAIL_G'")
sql "INSERT INTO evento_tareas (evento_id, titulo, vence) VALUES ($EV_A, 'Confirmar el DJ', '$HOY'::date + 1)" > /dev/null
sql "INSERT INTO actividades (negocio_id, evento_id, cliente_id, tipo, texto, programada_para, hecha, usuario_id) SELECT $NID, $EV_C, cliente_id, 'llamada', 'Llamar para la degustación', now() - interval '1 hour', false, $UID_G FROM eventos WHERE id = $EV_C" > /dev/null
sql "INSERT INTO notificaciones (negocio_id, usuario_id, evento_id, texto) VALUES ($NID, $UID_G, $EV_A, 'El cliente subió un comprobante de prueba')" > /dev/null
get "r=inicio" > /dev/null
contiene "Próximo evento en 3 días" "$TMP/pag.html" "Boda de Ana y Luis"
contiene "Debe saldo" "$TMP/pag.html" "Debe \$2.000,00"
contiene "Vencido por cobrar" "$TMP/pag.html" "\$1.500,00"
contiene "Seguimiento de hoy" "$TMP/pag.html" "Llamar para la degustación"
contiene "Tarea por vencer" "$TMP/pag.html" "Confirmar el DJ"
contiene "Fecha retenida que vence" "$TMP/pag.html" "Boda Retenida"
contiene "Aviso sin leer" "$TMP/pag.html" "comprobante de prueba"
contiene "Cuota con WhatsApp" "$TMP/pag.html" "wa.me/593991234567"
no_contiene "Ya no sale primeros paso del evento" "$TMP/pag.html" "Crea tu primer evento</div>"
TAREA=$(sql "SELECT id FROM evento_tareas WHERE evento_id=$EV_A AND titulo='Confirmar el DJ'")
r=$(post_desde "r=inicio" "r=inicio&accion=tarea" --data-urlencode "id=$TAREA")
check "Marcar tarea hecha desde el inicio" "t" "$(sql "SELECT hecha FROM evento_tareas WHERE id=$TAREA")"
ACT=$(sql "SELECT id FROM actividades WHERE evento_id=$EV_C")
r=$(post_desde "r=inicio" "r=inicio&accion=seguimiento" --data-urlencode "id=$ACT")
check "Marcar seguimiento hecho" "t" "$(sql "SELECT hecha FROM actividades WHERE id=$ACT")"
r=$(post_desde "r=inicio" "r=inicio&accion=ocultar_pasos")
check "Ocultar primeros pasos" "t" "$(sql "SELECT pasos_ocultos FROM negocios WHERE id=$NID")"
get "r=inicio" > /dev/null
no_contiene "Primeros pasos ocultos" "$TMP/pag.html" "Ya sé usarlo, ocultar"

echo "3. Avisos (notificaciones)"
check "Avisos" "200" "$(get "r=notificaciones")"
contiene "Lista de avisos" "$TMP/pag.html" "comprobante de prueba"
contiene "Enlace al evento" "$TMP/pag.html" "r=evento&amp;id=$EV_A"
contiene "Botón marcar leídos" "$TMP/pag.html" "Marcar todos como leídos"
r=$(post_desde "r=notificaciones" "r=notificaciones&accion=leidas")
check "Marcar todos leídos" "0" "$(sql "SELECT count(*) FROM notificaciones WHERE usuario_id=$UID_G AND NOT leida")"

echo "4. Reportes y exportar"
sql "INSERT INTO pagos (negocio_id, evento_id, tipo, monto, metodo, estado, fecha) VALUES ($NID, $EV_A, 'evento', 500, 'deuna', 'confirmado', now())" > /dev/null
sql "INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES ($NID, $EV_A, 'Prueba', 'Cambió el estado de Cotizado a Separado')" > /dev/null
check "Reportes" "200" "$(get "r=reportes")"
contiene "Ventas" "$TMP/pag.html" "Eventos vendidos"
contiene "Cobrado" "$TMP/pag.html" "Cobrado por mes"
contiene "Cobrado por DeUna" "$TMP/pag.html" "DeUna"
contiene "Ocupación" "$TMP/pag.html" "Sábados vendidos por mes"
contiene "Embudo" "$TMP/pag.html" "Embudo de ventas"
contiene "Rentabilidad" "$TMP/pag.html" "Ganancia por evento"
contiene "Comisión" "$TMP/pag.html" "Comisión de vendedores"
contiene "Gráfico de columnas" "$TMP/pag.html" "g-col-barra"
contiene "Enlace de descarga" "$TMP/pag.html" "r=exportar&amp;tipo=eventos"
check "Reportes del mes pasado" "200" "$(get "r=reportes&p=mes_pasado")"
check "Reportes del año" "200" "$(get "r=reportes&p=anio")"
check "Reportes por rango" "200" "$(get "r=reportes&p=rango&desde=2026-01-01&hasta=2027-12-31")"
contiene "Rango aplicado" "$TMP/pag.html" "Del 01/01/2026 al 31/12/2027"
check "Exportar eventos" "200" "$(get "r=exportar&tipo=eventos&desde=2020-01-01&hasta=2030-12-31")"
check "CSV con BOM UTF-8" "efbbbf" "$(head -c 3 "$TMP/pag.html" | od -An -tx1 | tr -d ' \n')"
contiene "CSV con ; y encabezados en español" "$TMP/pag.html" "Código;Evento;Tipo;Fecha"
contiene "CSV trae el evento" "$TMP/pag.html" "Boda de Ana y Luis"
contiene "CSV con montos con coma" "$TMP/pag.html" "2000,00"
check "Tipo de archivo CSV" "text/csv; charset=utf-8" "$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$BASE/?r=exportar&tipo=pagos&desde=2020-01-01&hasta=2030-12-31")"
for T in pagos clientes cuotas gastos; do
  check "Exportar $T" "200" "$(get "r=exportar&tipo=$T&desde=2020-01-01&hasta=2030-12-31")"
done
check "Exportar tipo que no existe: 404" "404" "$(get "r=exportar&tipo=nada")"

echo "5. Configuración"
check "Configuración" "200" "$(get "r=configuracion")"
contiene "Datos del negocio" "$TMP/pag.html" "Razón social"
r=$(post_desde "r=configuracion" "r=configuracion&tab=datos&accion=datos" --data-urlencode "nombre=Quinta Gestión Renovada" \
     --data-urlencode "ruc=1790000000001" --data-urlencode "whatsapp=0990000000" --data-urlencode "direccion=Vía a Tumbaco km 5" \
     --data-urlencode "ciudad=Tumbaco" --data-urlencode "instagram=@quintagestion" --data-urlencode "color=#3E7C59" --data-urlencode "descripcion=Jardines y salón")
check "Guardar datos lleva a la pestaña" "302 $BASE/?r=configuracion&tab=datos" "$r"
check "Nombre guardado" "Quinta Gestión Renovada" "$(sql "SELECT nombre FROM negocios WHERE id=$NID")"
check "Instagram sin @" "quintagestion" "$(sql "SELECT instagram FROM negocios WHERE id=$NID")"
r=$(post_desde "r=configuracion" "r=configuracion&tab=datos&accion=datos" --data-urlencode "nombre=Quinta Gestión Renovada" --data-urlencode "ruc=123" --data-urlencode "color=#3E7C59")
get "r=configuracion&tab=datos" > /dev/null
contiene "Error de RUC" "$TMP/pag.html" "El RUC debe tener 13 dígitos"
contiene "No se pierde lo escrito" "$TMP/pag.html" 'value="123"'
# Logo (subida de archivo)
php -r '$i=imagecreatetruecolor(200,200); imagefill($i,0,0,imagecolorallocate($i,107,45,60)); imagepng($i, $argv[1]);' "$TMP/logo.png"
get "r=configuracion&tab=datos" > /dev/null
T=$(csrf)
r=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" -F "csrf=$T" -F "logo=@$TMP/logo.png;type=image/png" "$BASE/?r=configuracion&tab=datos&accion=logo")
check "Subir logo" "302" "$r"
LOGO=$(sql "SELECT logo FROM negocios WHERE id=$NID")
check "Logo guardado" "1" "$( [ -n "$LOGO" ] && [ -f "$TURENTA_UPLOADS/logos/$LOGO" ] && echo 1 || echo 0)"
check "Ver el logo" "200" "$(get "r=archivo&tipo=logo&id=$NID")"
r=$(post_desde "r=configuracion&tab=reglas" "r=configuracion&tab=reglas&accion=reglas" --data-urlencode "iva_pct=15" --data-urlencode "validez_cotizacion=20" \
     --data-urlencode "dias_retencion=4" --data-urlencode "reserva_pct=40" --data-urlencode "cuotas_defecto=4" --data-urlencode "dias_saldo_antes=10" \
     --data-urlencode "garantia_defecto=250,50" --data-urlencode "horas_montaje=2" --data-urlencode "horas_desmontaje=1" --data-urlencode "hora_limite=03:00" \
     --data-urlencode "invitados_por_mesero=12" --data-urlencode "meses_revisar_precio=8" --data-urlencode "comision_vendedor_pct=3" --data-urlencode "condiciones=Precios en dólares.")
check "Guardar reglas" "40.00|4|250.50|03:00:00|3.00" "$(sql "SELECT reserva_pct||'|'||cuotas_defecto||'|'||garantia_defecto||'|'||hora_limite||'|'||comision_vendedor_pct FROM negocios WHERE id=$NID")"
r=$(post_desde "r=configuracion&tab=reglas" "r=configuracion&tab=reglas&accion=reglas" --data-urlencode "iva_pct=99" --data-urlencode "validez_cotizacion=20" \
     --data-urlencode "dias_retencion=4" --data-urlencode "reserva_pct=40" --data-urlencode "cuotas_defecto=4" --data-urlencode "dias_saldo_antes=10" \
     --data-urlencode "garantia_defecto=0" --data-urlencode "horas_montaje=2" --data-urlencode "horas_desmontaje=1" --data-urlencode "hora_limite=03:00" \
     --data-urlencode "invitados_por_mesero=12" --data-urlencode "meses_revisar_precio=8" --data-urlencode "comision_vendedor_pct=3")
get "r=configuracion&tab=reglas" > /dev/null
contiene "IVA fuera de rango" "$TMP/pag.html" "El IVA (%) debe estar entre 0 y 30"
check "El IVA no cambió" "15.00" "$(sql "SELECT iva_pct FROM negocios WHERE id=$NID")"
check "Pestaña cobros" "200" "$(get "r=configuracion&tab=cobros")"
contiene "Ayuda de Payphone" "$TMP/pag.html" "Store ID"
r=$(post_desde "r=configuracion&tab=cobros" "r=configuracion&tab=cobros&accion=cobros" --data-urlencode "pago_banco=Banco Pichincha 2200" --data-urlencode "pago_deuna=0991112233" \
     --data-urlencode "payphone_token=TOKENdePRUEBA1234567890abcdef" --data-urlencode "payphone_store_id=store-123")
check "Cobros guardados" "store-123" "$(sql "SELECT payphone_store_id FROM negocios WHERE id=$NID")"
get "r=configuracion&tab=cobros" > /dev/null
no_contiene "El token no se muestra" "$TMP/pag.html" "TOKENdePRUEBA"
check "Página pública con QR" "200" "$(get "r=configuracion&tab=pagina")"
contiene "Enlace de la página" "$TMP/pag.html" "r=sitio&amp;s="
contiene "Código QR" "$TMP/pag.html" "<svg"
check "Plantilla de tareas" "200" "$(get "r=configuracion&tab=tareas")"
contiene "Tareas de ejemplo" "$TMP/pag.html" "Degustación del menú"
r=$(post_desde "r=configuracion&tab=tareas" "r=configuracion&tab=tareas&accion=tarea" --data-urlencode "titulo=Prueba de vestido" --data-urlencode "dias_antes=25" --data-urlencode "tipo_evento=quince")
TID=$(sql "SELECT id FROM plantilla_tareas WHERE negocio_id=$NID AND titulo='Prueba de vestido'")
check "Tarea agregada" "25" "$(sql "SELECT dias_antes FROM plantilla_tareas WHERE id=$TID")"
r=$(post_desde "r=configuracion&tab=tareas" "r=configuracion&tab=tareas&accion=tarea" --data-urlencode "id=$TID" --data-urlencode "titulo=Prueba del vestido" --data-urlencode "dias_antes=30" --data-urlencode "tipo_evento=quince")
check "Tarea editada" "Prueba del vestido|30" "$(sql "SELECT titulo||'|'||dias_antes FROM plantilla_tareas WHERE id=$TID")"
r=$(post_desde "r=configuracion&tab=tareas" "r=configuracion&tab=tareas&accion=borrar_tarea" --data-urlencode "id=$TID")
check "Tarea borrada" "0" "$(sql "SELECT count(*) FROM plantilla_tareas WHERE id=$TID")"
check "Google Calendar" "200" "$(get "r=configuracion&tab=calendario")"
contiene "Enlace iCal" "$TMP/pag.html" "r=ical&amp;t="
contiene "Instrucciones" "$TMP/pag.html" "Desde URL"
TOK1=$(sql "SELECT token_ical FROM negocios WHERE id=$NID")
r=$(post_desde "r=configuracion&tab=calendario" "r=configuracion&tab=calendario&accion=ical")
TOK2=$(sql "SELECT token_ical FROM negocios WHERE id=$NID")
check "Cambiar el enlace del calendario" "1" "$( [ "$TOK1" != "$TOK2" ] && echo 1 || echo 0)"
check "Pestaña Más" "200" "$(get "r=configuracion&tab=otros")"
contiene "Enlace a plantillas de contrato" "$TMP/pag.html" "r=contrato\""
r=$(post_desde "r=configuracion&tab=otros" "r=configuracion&tab=otros&accion=transmision" --data-urlencode "whatsapp_transmision=0987654321")
check "WhatsApp de transmisiones" "593987654321" "$(sql "SELECT whatsapp_transmision FROM negocios WHERE id=$NID")"

echo "6. iCal (Google Calendar)"
check "iCal con token bueno" "200" "$(curl -s -o "$TMP/cal.ics" -w '%{http_code}' "$BASE/?r=ical&t=$TOK2")"
contiene "BEGIN:VCALENDAR" "$TMP/cal.ics" "BEGIN:VCALENDAR"
contiene "Evento separado" "$TMP/cal.ics" "SUMMARY:Boda de Ana y Luis"
contiene "Fecha retenida marcada" "$TMP/cal.ics" "SUMMARY:RETENIDO · Boda Retenida"
contiene "Zona de Ecuador" "$TMP/cal.ics" "TZID=America/Guayaquil"
no_contiene "Sin la consulta" "$TMP/cal.ics" "Quince de Sofía"
check "Tipo text/calendar" "text/calendar; charset=utf-8" "$(curl -s -o /dev/null -w '%{content_type}' "$BASE/?r=ical&t=$TOK2")"
check "Token viejo: 404" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=ical&t=$TOK1")"
check "Token falso: 404" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=ical&t=abc")"

echo "7. Usuarios: crear vendedor y coordinador"
check "Usuarios" "200" "$(get "r=usuarios")"
VEND="vend$RANDOM@prueba.ec"
r=$(post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Carla Vendedora" --data-urlencode "email=$VEND" --data-urlencode "telefono=0991112222" --data-urlencode "rol=vendedor")
check "Crear vendedor" "vendedor" "$(sql "SELECT rol FROM usuarios WHERE email='$VEND'")"
get "r=usuarios" > /dev/null
CLAVE_V=$(grep -o 'class="clave-temporal">[^<]*' "$TMP/pag.html" | head -1 | sed 's/.*>//')
check "Se muestra la clave temporal" "10" "${#CLAVE_V}"
contiene "Enviar acceso por WhatsApp" "$TMP/pag.html" "wa.me/593991112222"
get "r=usuarios" > /dev/null
no_contiene "La clave se muestra una sola vez" "$TMP/pag.html" "$CLAVE_V"
r=$(post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Otra" --data-urlencode "email=$VEND" --data-urlencode "rol=vendedor")
get "r=usuarios" > /dev/null
contiene "Correo repetido" "$TMP/pag.html" "Ya existe un usuario con ese correo"
COORD="coord$RANDOM@prueba.ec"
r=$(post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Pedro Coordinador" --data-urlencode "email=$COORD" --data-urlencode "rol=coordinador")
get "r=usuarios" > /dev/null
CLAVE_C=$(grep -o 'class="clave-temporal">[^<]*' "$TMP/pag.html" | head -1 | sed 's/.*>//')
r=$(post_desde "r=usuarios" "r=usuarios&accion=activar" --data-urlencode "id=$UID_G" --data-urlencode "activo=0")
get "r=usuarios" > /dev/null
contiene "El dueño no se desactiva" "$TMP/pag.html" "a ti mismo"
check "El dueño sigue activo" "t" "$(sql "SELECT activo FROM usuarios WHERE id=$UID_G")"

echo "8. Entrar como vendedor y como coordinador"
entrar "$VEND" "$CLAVE_V"
check "El vendedor entra al inicio" "200" "$(get "r=inicio")"
contiene "Aviso de clave temporal" "$TMP/pag.html" "contraseña temporal"
check "El vendedor ve reportes" "200" "$(get "r=reportes")"
check "El vendedor no entra a configuración" "403" "$(get "r=configuracion")"
check "El vendedor no entra a usuarios" "403" "$(get "r=usuarios")"
check "El vendedor no entra a planes" "403" "$(get "r=planes")"
r=$(post_desde "r=mi_cuenta" "r=mi_cuenta&accion=clave" --data-urlencode "actual=$CLAVE_V" --data-urlencode "nueva=MiClaveNueva9" --data-urlencode "repetir=MiClaveNueva9")
check "El vendedor cambia su clave" "f" "$(sql "SELECT clave_temporal FROM usuarios WHERE email='$VEND'")"
entrar "$VEND" "MiClaveNueva9"
check "Entra con la clave nueva" "200" "$(get "r=inicio")"
no_contiene "Ya no pide cambiar la clave" "$TMP/pag.html" "Entraste con una contraseña temporal"
entrar "$COORD" "$CLAVE_C"
check "El coordinador entra al inicio" "200" "$(get "r=inicio")"
no_contiene "El coordinador no ve dinero" "$TMP/pag.html" "Vencido por cobrar"
no_contiene "El coordinador no ve cobros" "$TMP/pag.html" "Cuotas vencidas o por vencer"
contiene "El coordinador ve los eventos de la semana" "$TMP/pag.html" "Boda de Ana y Luis"
check "El coordinador NO ve reportes (vuelve al inicio)" "302" "$(get "r=reportes")"
check "El coordinador NO exporta" "302" "$(get "r=exportar&tipo=pagos")"
check "El coordinador sí entra a mi cuenta" "200" "$(get "r=mi_cuenta")"

echo "9. Mi cuenta (dueño)"
entrar "$EMAIL_G" "claveSegura1"
check "Mi cuenta" "200" "$(get "r=mi_cuenta")"
contiene "Sección Telegram" "$TMP/pag.html" "Avisos en Telegram"
r=$(post_desde "r=mi_cuenta" "r=mi_cuenta&accion=perfil" --data-urlencode "nombre=Dimitry Fierro" --data-urlencode "telefono=0995556666")
check "Perfil guardado" "Dimitry Fierro|0995556666" "$(sql "SELECT nombre||'|'||telefono FROM usuarios WHERE id=$UID_G")"
r=$(post_desde "r=mi_cuenta" "r=mi_cuenta&accion=clave" --data-urlencode "actual=mala" --data-urlencode "nueva=OtraClave123" --data-urlencode "repetir=OtraClave123")
get "r=mi_cuenta" > /dev/null
contiene "Clave actual incorrecta" "$TMP/pag.html" "La contraseña actual no es correcta"
r=$(post_desde "r=mi_cuenta" "r=mi_cuenta&accion=clave" --data-urlencode "actual=claveSegura1" --data-urlencode "nueva=OtraClave123" --data-urlencode "repetir=OtraClave123")
get "r=mi_cuenta" > /dev/null
contiene "Clave cambiada" "$TMP/pag.html" "tu contraseña cambió"
entrar "$EMAIL_G" "OtraClave123"
check "El dueño entra con la clave nueva" "200" "$(get "r=inicio")"

echo "10. Usuario de solo consulta"
CONS="cons$RANDOM@prueba.ec"
r=$(post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Contador" --data-urlencode "email=$CONS" --data-urlencode "rol=consulta")
get "r=usuarios" > /dev/null
CLAVE_K=$(grep -o 'class="clave-temporal">[^<]*' "$TMP/pag.html" | head -1 | sed 's/.*>//')
entrar "$CONS" "$CLAVE_K"
check "Consulta ve el inicio" "200" "$(get "r=inicio")"
contiene "Consulta ve el dinero" "$TMP/pag.html" "Vencido por cobrar"
no_contiene "Consulta sin botón Nuevo evento" "$TMP/pag.html" "r=evento_nuevo"
no_contiene "Consulta sin botones de marcar" "$TMP/pag.html" "accion=tarea"
check "Consulta ve reportes" "200" "$(get "r=reportes")"
entrar "$EMAIL_G" "OtraClave123"

echo "11. Límite de usuarios del plan Salón"
registrar salon "Salón Límite $RANDOM"
NID_S=$NID
for i in 1 2; do
  post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Persona $i" --data-urlencode "email=p$i$RANDOM@salon.ec" --data-urlencode "rol=vendedor" > /dev/null
done
check "Plan Salón: 3 usuarios" "3" "$(sql "SELECT count(*) FROM usuarios WHERE negocio_id=$NID_S AND activo")"
get "r=usuarios" > /dev/null
contiene "Mensaje claro del límite" "$TMP/pag.html" "permite 3 usuarios activos"
post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=Cuarta" --data-urlencode "email=cuarta$RANDOM@salon.ec" --data-urlencode "rol=vendedor" > /dev/null
check "No se crea el cuarto" "3" "$(sql "SELECT count(*) FROM usuarios WHERE negocio_id=$NID_S")"

echo "12. Planes y prueba terminada"
check "Planes" "200" "$(get "r=planes")"
contiene "Plan Quinta" "$TMP/pag.html" "Plan Quinta"
contiene "Precio Salón" "$TMP/pag.html" "\$39,00"
contiene "Semestral" "$TMP/pag.html" "Cada 6 meses"
contiene "Pagar por WhatsApp" "$TMP/pag.html" "wa.me/593983662181?text="
contiene "Cuánto pagas" "$TMP/pag.html" "Cuánto pagas"
check "Con la prueba vigente, prueba_terminada vuelve al inicio" "302" "$(get "r=prueba_terminada")"
sql "UPDATE negocios SET prueba_hasta = '$HOY'::date - 1 WHERE id=$NID_S" > /dev/null
check "Prueba vencida lleva a prueba_terminada" "302" "$(get "r=inicio")"
check "Pantalla de prueba terminada" "200" "$(get "r=prueba_terminada")"
contiene "Mensaje de demo terminada" "$TMP/pag.html" "Tu demo terminó — contáctanos para contratar"
contiene "Datos guardados" "$TMP/pag.html" "Todo está guardado"
check "Con la prueba vencida sí puede ver planes" "200" "$(get "r=planes")"

echo "13. Telegram (webhook)"
check "Sin secreto: 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d '{}' "$BASE/?r=telegram")"
check "Con secreto falso: 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'X-Telegram-Bot-Api-Secret-Token: falso' -d '{}' "$BASE/?r=telegram")"
check "Por GET: 403" "403" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=telegram")"
if [ -n "${TELEGRAM_WEBHOOK_SECRET:-}" ]; then
  check "Con el secreto: 200" "200" "$(curl -s -o "$TMP/tg.json" -w '%{http_code}' -X POST -H "X-Telegram-Bot-Api-Secret-Token: $TELEGRAM_WEBHOOK_SECRET" \
       -d '{"message":{"chat":{"id":123},"text":"/start"}}' "$BASE/?r=telegram")"
  contiene "Responde ok" "$TMP/tg.json" '"ok":true'
else
  echo "  (sin TELEGRAM_WEBHOOK_SECRET en el sitio de prueba: no se prueba la llamada buena)"
fi

echo "14. Tareas automáticas desde la consola"
OUT=$(env $DBENV php bin/tareas.php "$(date +%Y-%m-%d) 09:00" 2>&1)
contiene_txt() { if echo "$2" | grep -q -- "$3"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — [$2]"; fi; }
contiene_txt "bin/tareas.php imprime el resumen" "$OUT" "negocios"
OUT=$(env $DBENV php bin/tareas.php "fecha mala" 2>&1)
contiene_txt "Fecha mala: mensaje claro" "$OUT" "Fecha no valida"

echo "15. Panel Tukán"
ADM="admin$RANDOM@tukan.ec"
OUT=$(env $DBENV TURENTA_ADMIN_NOMBRE="Dimitry Tukán" TURENTA_ADMIN_EMAIL="$ADM" TURENTA_ADMIN_CLAVE="ClaveDelPanel123" php bin/crear_admin.php 2>&1)
contiene_txt "crear_admin crea el super usuario" "$OUT" "super usuario creado"
OUT=$(env $DBENV TURENTA_ADMIN_NOMBRE="Dimitry Tukán" TURENTA_ADMIN_EMAIL="$ADM" TURENTA_ADMIN_CLAVE="ClaveDelPanel123" php bin/crear_admin.php 2>&1)
contiene_txt "Correrlo otra vez cambia la clave" "$OUT" "contrasena cambiada"
OUT=$(env $DBENV TURENTA_ADMIN_NOMBRE="Xavier" TURENTA_ADMIN_EMAIL="corto@tukan.ec" TURENTA_ADMIN_CLAVE="corta" php bin/crear_admin.php 2>&1)
contiene_txt "Clave corta: no se crea" "$OUT" "al menos 10"
rm -f "$JAR"
check "Sin sesión, el panel lleva a entrar" "302" "$(get "r=admin")"
check "Página de entrada del panel" "200" "$(get "r=admin_entrar")"
r=$(post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=mala")
contiene "Clave mala en el panel" "$TMP/post.html" "no coinciden"
r=$(post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=ClaveDelPanel123")
check "Entrar al panel" "302 $BASE/?r=admin" "$r"
check "Resumen del panel" "200" "$(get "r=admin")"
contiene "Cifras del panel" "$TMP/pag.html" "Ingreso mensual recurrente"
contiene "Estados" "$TMP/pag.html" "Demo terminada"
check "Lista de negocios" "200" "$(get "r=admin_negocios")"
contiene "Aparece el negocio" "$TMP/pag.html" "Quinta Gestión Renovada"
check "Buscar negocio" "200" "$(get "r=admin_negocios&q=Renovada")"
contiene "Búsqueda encuentra" "$TMP/pag.html" "Negocios (1)"
check "Filtrar por estado" "200" "$(get "r=admin_negocios&f=prueba_vencida")"
contiene "Filtro: el salón con prueba vencida" "$TMP/pag.html" "Salón Límite"
check "Ficha del negocio" "200" "$(get "r=admin_negocio&id=$NID_S")"
contiene "Formulario de pago" "$TMP/pag.html" 'id="form-pago"'
contiene "Cotizaciones para el script" "$TMP/pag.html" "data-cotizaciones="
r=$(post_desde "r=admin_negocio&id=$NID_S" "r=admin_negocio&id=$NID_S&accion=pago" --data-urlencode "plan=salon" --data-urlencode "periodo=semestral" \
     --data-urlencode "inicio=$HOY" --data-urlencode "metodo=transferencia" --data-urlencode "monto=")
check "Registrar pago" "302 $BASE/?r=admin_negocio&id=$NID_S" "$r"
check "Pago semestral (paga 5)" "195.00|6" "$(sql "SELECT monto||'|'||meses_recibidos FROM suscripciones WHERE negocio_id=$NID_S")"
check "Negocio activo" "activo" "$(sql "SELECT estado FROM negocios WHERE id=$NID_S")"
check "Quedó registrado" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE negocio_id=$NID_S AND accion LIKE 'Pago registrado%'")"
SUS=$(sql "SELECT id FROM suscripciones WHERE negocio_id=$NID_S")
r=$(post_desde "r=admin_negocio&id=$NID_S" "r=admin_negocio&id=$NID_S&accion=anular_pago" --data-urlencode "pago=$SUS")
check "Anular pago" "t" "$(sql "SELECT anulada FROM suscripciones WHERE id=$SUS")"
r=$(post_desde "r=admin_negocio&id=$NID_S" "r=admin_negocio&id=$NID_S&accion=pago" --data-urlencode "plan=quinta" --data-urlencode "periodo=mensual" \
     --data-urlencode "inicio=$HOY" --data-urlencode "metodo=deuna" --data-urlencode "monto=60")
check "Pago con monto especial y cambio a Quinta" "60.00|quinta" "$(sql "SELECT s.monto||'|'||n.plan_codigo FROM suscripciones s JOIN negocios n ON n.id=s.negocio_id WHERE s.negocio_id=$NID_S AND NOT s.anulada")"
r=$(post_desde "r=admin_negocio&id=$NID_G" "r=admin_negocio&id=$NID_G&accion=prueba" --data-urlencode "dias=7")
check "Extender prueba" "$(date -d "+14 days" +%Y-%m-%d)" "$(sql "SELECT prueba_hasta FROM negocios WHERE id=$NID_G")"
r=$(post_desde "r=admin_negocio&id=$NID_G" "r=admin_negocio&id=$NID_G&accion=notas" --data-urlencode "notas=Cliente piloto")
check "Notas internas" "Cliente piloto" "$(sql "SELECT notas_admin FROM negocios WHERE id=$NID_G")"
r=$(post_desde "r=admin_negocio&id=$NID_S" "r=admin_negocio&id=$NID_S&accion=estado" --data-urlencode "estado=suspendido")
check "Suspender" "suspendido" "$(sql "SELECT estado FROM negocios WHERE id=$NID_S")"
r=$(post_desde "r=admin_negocio&id=$NID_S" "r=admin_negocio&id=$NID_S&accion=estado" --data-urlencode "estado=activo")
r=$(post_desde "r=admin_negocio&id=$NID_S" "r=admin_negocio&id=$NID_S&accion=plan" --data-urlencode "plan=salon")
check "Cambiar plan" "salon" "$(sql "SELECT plan_codigo FROM negocios WHERE id=$NID_S")"
r=$(post_desde "r=admin_negocio&id=$NID_S" "r=admin_negocio&id=$NID_S&accion=clave")
get "r=admin_negocio&id=$NID_S" > /dev/null
CLAVE_D=$(grep -o 'class="clave-temporal">[^<]*' "$TMP/pag.html" | head -1 | sed 's/.*>//')
check "Nueva clave del dueño (se muestra)" "10" "${#CLAVE_D}"
check "Planes y precios" "200" "$(get "r=admin_planes")"
contiene "Máximo de espacios" "$TMP/pag.html" "Máximo de espacios"
r=$(post_desde "r=admin_planes" "r=admin_planes" --data-urlencode "accion=plan" --data-urlencode "codigo=salon" --data-urlencode "nombre=Salón" \
     --data-urlencode "precio_mensual=42" --data-urlencode "max_espacios=3" --data-urlencode "max_usuarios=4" --data-urlencode "descripcion=Para salones")
check "Guardar plan con máximos" "42.00|3|4" "$(sql "SELECT precio_mensual||'|'||max_espacios||'|'||max_usuarios FROM planes WHERE codigo='salon'")"
r=$(post_desde "r=admin_planes" "r=admin_planes" --data-urlencode "accion=ajustes" --data-urlencode "dias_prueba=21" --data-urlencode "dias_gracia=3" \
     --data-urlencode "whatsapp_ventas=593983662181" --data-urlencode "semestral_paga=5" --data-urlencode "semestral_recibe=6" --data-urlencode "anual_paga=10" --data-urlencode "anual_recibe=12")
check "Guardar ajustes" "21" "$(sql "SELECT valor FROM ajustes WHERE clave='dias_prueba'")"
sql "UPDATE planes SET precio_mensual=39, max_espacios=2, max_usuarios=3 WHERE codigo='salon'; UPDATE ajustes SET valor='7' WHERE clave='dias_prueba'" > /dev/null
get "r=admin_planes" > /dev/null
contiene "Registro de acciones del panel" "$TMP/pag.html" "Plan salon actualizado"
check "Nuevo negocio (formulario)" "200" "$(get "r=admin_nuevo")"
NUEVO="nuevo$RANDOM@prueba.ec"
r=$(post_desde "r=admin_nuevo" "r=admin_nuevo" --data-urlencode "negocio=Hacienda Nueva $RANDOM" --data-urlencode "nombre=Rosa Dueña" \
     --data-urlencode "telefono=0997778888" --data-urlencode "email=$NUEVO" --data-urlencode "ciudad=Cuenca" --data-urlencode "plan=quinta")
NID_N=$(sql "SELECT negocio_id FROM usuarios WHERE email='$NUEVO'")
check "Nuevo negocio creado" "302 $BASE/?r=admin_negocio&id=$NID_N" "$r"
get "r=admin_negocio&id=$NID_N" > /dev/null
contiene "Muestra la clave del dueño nuevo" "$TMP/pag.html" "clave-temporal"
contiene "WhatsApp para enviar el acceso" "$TMP/pag.html" "wa.me/593997778888"
check "Ver como negocio: solo por POST" "302" "$(get "r=admin_ver&id=$NID_G")"
r=$(post_desde "r=admin_negocio&id=$NID_G" "r=admin_ver&id=$NID_G")
check "Ver como el negocio" "302 $BASE/?r=inicio" "$r"
check "Inicio del negocio visto por Tukán" "200" "$(get "r=inicio")"
contiene "Franja de soporte" "$TMP/pag.html" "como administrador de Tukán"
contiene "Es el negocio correcto" "$TMP/pag.html" "Quinta Gestión Renovada"
check "Quedó en el registro" "1" "$(sql "SELECT count(*) FROM registro_admin WHERE negocio_id=$NID_G AND accion LIKE 'Entró al negocio%'")"
r=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code} %{redirect_url}" "$BASE/?r=admin_volver")
check "Volver al panel" "302 $BASE/?r=admin_negocio&id=$NID_G" "$r"
check "Ya no está dentro del negocio" "302" "$(get "r=reportes")"
check "Sigue en el panel" "200" "$(get "r=admin")"
r=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code} %{redirect_url}" "$BASE/?r=admin_salir")
check "Salir del panel" "302 $BASE/?r=admin_entrar" "$r"
check "Ya no entra al panel" "302" "$(get "r=admin")"
# Freno de intentos
rm -f "$JAR"
for i in 1 2 3 4 5; do post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=mala$i" > /dev/null; done
r=$(post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=ClaveDelPanel123")
contiene "Freno después de 5 intentos" "$TMP/post.html" "Demasiados intentos"
rm -f "$JAR"
r=$(post "r=admin_entrar" --data-urlencode "email=$ADM" --data-urlencode "clave=ClaveDelPanel123")
contiene "El freno sigue aunque cambie de navegador" "$TMP/post.html" "Demasiados intentos"
sql "DELETE FROM registro_admin WHERE accion LIKE 'Entrada fallida:%'" > /dev/null

echo "16. Primeros pasos: marcar precios revisados"
registrar quinta "Quinta Pasos $RANDOM"
get "r=inicio" > /dev/null
contiene "Botón Ya lo hice" "$TMP/pag.html" "accion=precios_revisados"
r=$(post_desde "r=inicio" "r=inicio&accion=precios_revisados")
check "Precios marcados como revisados" "t" "$(sql "SELECT precios_revisados FROM negocios WHERE id=$NID")"
get "r=inicio" > /dev/null
no_contiene "Ya no aparece el botón" "$TMP/pag.html" "accion=precios_revisados"
