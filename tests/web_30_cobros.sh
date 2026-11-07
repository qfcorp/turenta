# Cobros y portal del cliente: pagos del evento hasta separar la fecha, plan automático y a mano, anular,
# comprobante desde el portal (confirmar / rechazar), Payphone simulado, recibo y estado de cuenta en PDF,
# tablero de cobros, mensajes de hoy y permisos.
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh
FOTO="$TMP/comprobante_30.jpg"
php -r '$i=imagecreatetruecolor(320,240);imagefill($i,0,0,imagecolorallocate($i,210,230,250));imagestring($i,5,40,100,"Transferencia 500",0);imagejpeg($i,$argv[1]);' "$FOTO"
pdf_get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/doc.pdf" -w "%{http_code} %{content_type}" "$BASE/?$1"; }
subir_comprobante() { # token monto metodo
  get "r=portal&t=$1" > /dev/null
  local t; t=$(csrf)
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" -F "csrf=$t" -F "monto=$2" -F "metodo=$3" \
       -F "referencia=REF$RANDOM" -F "comprobante=@$FOTO;type=image/jpeg" "$BASE/?r=portal_pagar&t=$1&accion=comprobante"
}

echo "1. Evento con valor y plan de pagos automático"
registrar quinta "Hacienda Cobros $RANDOM"
NID1=$NID; EMAIL1=$EMAIL
FECHA=$(date -d "+90 days" +%F)
EV=$(crear_evento_sql "$FECHA" consulta)
sql "UPDATE eventos SET total = 3000, costo_estimado = 1200 WHERE id = $EV" > /dev/null
sql "UPDATE clientes SET identificacion = '1712345678' WHERE id = (SELECT cliente_id FROM eventos WHERE id = $EV)" > /dev/null
TOKEN=$(sql "SELECT token_cliente FROM eventos WHERE id = $EV")
check "Pantalla de pagos del evento" "200" "$(get "r=evento_pagos&id=$EV")"
contiene "Sin plan: ofrece armarlo" "$TMP/pag.html" "Armar el plan automático"
contiene "Pestañas del evento" "$TMP/pag.html" "Día del evento"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=regenerar" --data-urlencode "cuotas=3" --data-urlencode "reserva_pct=30")
check "Armar plan redirige" "302 $BASE/?r=evento_pagos&id=$EV#plan" "$r"
check "Plan de 3 pagos" "3" "$(sql "SELECT count(*) FROM cuotas WHERE evento_id = $EV")"
check "Reserva 30 % = 900" "900.00" "$(sql "SELECT monto FROM cuotas WHERE evento_id = $EV AND numero = 1")"
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Muestra la reserva" "$TMP/pag.html" "Reserva"
contiene "Monto sugerido = reserva" "$TMP/pag.html" 'name="monto" required value="900,00"'
contiene "Barra de avance" "$TMP/pag.html" "0 % pagado"
contiene "Botón de WhatsApp para recordar" "$TMP/pag.html" "wa.me/593991234567"
contiene "Enlace del portal" "$TMP/pag.html" "r=portal&amp;t=$TOKEN"

echo "2. Registrar pagos hasta separar la fecha"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=pago" --data-urlencode "monto=500" --data-urlencode "metodo=transferencia" \
     --data-urlencode "referencia=PICH-555" --data-urlencode "fecha=$(date +%F)")
check "Pago 1 redirige a la lista" "302 $BASE/?r=evento_pagos&id=$EV#pagos" "$r"
check "Con 500 sigue en consulta" "consulta" "$(sql "SELECT estado FROM eventos WHERE id = $EV")"
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Aviso del recibo" "$TMP/pag.html" "Pago registrado (recibo N.º 1)"
contiene "Sugiere lo que falta de la reserva" "$TMP/pag.html" 'value="400,00"'
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=pago" --data-urlencode "monto=400" --data-urlencode "metodo=efectivo")
check "Al completar la reserva: SEPARADO" "separado" "$(sql "SELECT estado FROM eventos WHERE id = $EV")"
check "El espacio queda bloqueado" "t" "$(sql "SELECT bloquea FROM evento_espacios WHERE evento_id = $EV")"
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Mensaje: la fecha quedó separada" "$TMP/pag.html" "Reserva pagada: la fecha quedó separada."
contiene "30 % pagado" "$TMP/pag.html" "30 % pagado"
contiene "Reserva pagada" "$TMP/pag.html" "cb-etq-pagada"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=pago" --data-urlencode "monto=0" --data-urlencode "metodo=efectivo")
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Pago sin valor: error claro" "$TMP/pag.html" "Escribe el valor del pago."
P1=$(sql "SELECT id FROM pagos WHERE evento_id = $EV AND referencia = 'PICH-555'")

echo "3. Recibo y estado de cuenta en PDF"
check "Recibo PDF: 200 application/pdf" "200 application/pdf" "$(pdf_get "r=pdf&tipo=recibo&id=$P1")"
check "Es un PDF de verdad" "%PDF" "$(head -c 4 "$TMP/doc.pdf")"
pdftotext "$TMP/doc.pdf" "$TMP/doc.txt" 2>/dev/null
contiene "Recibo con el monto en letras" "$TMP/doc.txt" "Quinientos dólares con 00/100"
contiene "Recibo dice que no reemplaza a la factura" "$TMP/doc.txt" "Este recibo no reemplaza a la factura"
contiene "Recibo con la cédula" "$TMP/doc.txt" "1712345678"
check "Estado de cuenta PDF" "200 application/pdf" "$(pdf_get "r=pdf&tipo=estado_cuenta&id=$EV")"
pdftotext "$TMP/doc.pdf" "$TMP/doc.txt" 2>/dev/null
contiene "Estado de cuenta con el plan" "$TMP/doc.txt" "PLAN DE PAGOS"

echo "4. Editar el plan a mano"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=plan" \
     --data-urlencode "concepto[]=Reserva" --data-urlencode "vence[]=$(date +%F)" --data-urlencode "monto_cuota[]=900" \
     --data-urlencode "concepto[]=Saldo" --data-urlencode "vence[]=$(date -d "+60 days" +%F)" --data-urlencode "monto_cuota[]=1000")
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Plan que no suma el total: error" "$TMP/pag.html" "Deben ser iguales"
check "El plan no cambió" "3" "$(sql "SELECT count(*) FROM cuotas WHERE evento_id = $EV")"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=plan" \
     --data-urlencode "concepto[]=Reserva" --data-urlencode "vence[]=$(date +%F)" --data-urlencode "monto_cuota[]=900" \
     --data-urlencode "concepto[]=Segunda cuota" --data-urlencode "vence[]=$(date -d "+30 days" +%F)" --data-urlencode "monto_cuota[]=1.050,00" \
     --data-urlencode "concepto[]=Saldo final" --data-urlencode "vence[]=$(date -d "+60 days" +%F)" --data-urlencode "monto_cuota[]=1050" \
     --data-urlencode "concepto[]=" --data-urlencode "vence[]=" --data-urlencode "monto_cuota[]=")
check "Plan a mano guardado" "302 $BASE/?r=evento_pagos&id=$EV#plan" "$r"
check "Plan a mano: 3 pagos" "3" "$(sql "SELECT count(*) FROM cuotas WHERE evento_id = $EV")"
check "Segunda cuota con 1.050,00" "1050.00" "$(sql "SELECT monto FROM cuotas WHERE evento_id = $EV AND concepto = 'Segunda cuota'")"
check "Se volvió a aplicar lo pagado" "900.00" "$(sql "SELECT pagado FROM cuotas WHERE evento_id = $EV AND numero = 1")"

echo "5. Anular un pago"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=anular" --data-urlencode "pago_id=$P1" --data-urlencode "motivo=")
check "Anular sin motivo: no se anula" "confirmado" "$(sql "SELECT estado FROM pagos WHERE id = $P1")"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=anular" --data-urlencode "pago_id=$P1" --data-urlencode "motivo=Se registró dos veces")
check "Pago anulado" "anulado" "$(sql "SELECT estado FROM pagos WHERE id = $P1")"
check "La reserva vuelve a quedar con saldo" "400.00" "$(sql "SELECT pagado FROM cuotas WHERE evento_id = $EV AND numero = 1")"
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Aviso de anulado" "$TMP/pag.html" "Pago anulado"
contiene "Pide confirmación antes de anular" "$TMP/pag.html" "data-confirmar=\"¿Anular este pago"
check "Pago anulado ya no tiene recibo" "200 text/html; charset=UTF-8" "$(pdf_get "r=pdf&tipo=recibo&id=$P1")"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=pago" --data-urlencode "monto=500" --data-urlencode "metodo=transferencia")

echo "6. Portal del cliente"
check "Portal con token válido" "200" "$(get "r=portal&t=$TOKEN")"
contiene "Saludo al cliente" "$TMP/pag.html" "Hola, Cliente"
contiene "Fecha larga del evento" "$TMP/pag.html" "$(php -r 'require "app/bootstrap.php"; echo ucfirst(fecha_larga($argv[1]));' "$FECHA")"
contiene "Cuenta regresiva" "$TMP/pag.html" "data-cb-cuenta"
contiene "Plan de pagos en el portal" "$TMP/pag.html" "Segunda cuota"
contiene "Recibos públicos" "$TMP/pag.html" "r=portal&amp;t=$TOKEN&amp;recibo="
contiene "Plan Quinta: invitados" "$TMP/pag.html" "r=portal_invitados&amp;t=$TOKEN"
contiene "Plan Quinta: cronograma" "$TMP/pag.html" "Cronograma del evento"
no_contiene "Sin datos de pago: no muestra el formulario" "$TMP/pag.html" 'name="comprobante"'
contiene "Sin datos de pago: pide escribir al local" "$TMP/pag.html" "te enviará los datos"
check "Portal con token falso: 404" "404" "$(get "r=portal&t=abcdef0123456789abcdef0123456789abcdef0123456789")"
contiene "Token falso: mensaje amable" "$TMP/pag.html" "no es válido"
check "Portal sin token: 404" "404" "$(get "r=portal")"
P2=$(sql "SELECT id FROM pagos WHERE evento_id = $EV AND estado = 'confirmado' ORDER BY id DESC LIMIT 1")
check "Recibo desde el portal (público)" "200 application/pdf" "$(pdf_get "r=portal&t=$TOKEN&recibo=$P2")"
sql "UPDATE negocios SET pago_banco = 'Banco Pichincha · Cta. ahorros 2200112233 · Hacienda Cobros', pago_deuna = '0991234567' WHERE id = $NID1" > /dev/null
sql "UPDATE eventos SET transmision_url = 'https://vivo.qfproducciones.com/boda-prueba' WHERE id = $EV" > /dev/null
get "r=portal&t=$TOKEN" > /dev/null
contiene "Datos para transferir" "$TMP/pag.html" "Cta. ahorros 2200112233"
contiene "DeUna del negocio" "$TMP/pag.html" "0991234567"
contiene "Formulario para subir comprobante" "$TMP/pag.html" 'name="comprobante"'
contiene "Transmisión: Ver en vivo" "$TMP/pag.html" "Ver en vivo"
contiene "WhatsApp con el negocio" "$TMP/pag.html" "Escribir por WhatsApp"
no_contiene "Sin claves Payphone: no hay tarjeta" "$TMP/pag.html" "Pagar con tarjeta"

echo "7. Comprobante subido desde el portal -> por revisar -> confirmar / rechazar"
r=$(subir_comprobante "$TOKEN" "500" "transferencia")
check "Subir comprobante redirige al portal" "302 $BASE/?r=portal&t=$TOKEN#pagos" "$r"
check "Queda por revisar" "1" "$(sql "SELECT count(*) FROM pagos WHERE evento_id = $EV AND estado = 'por_revisar'")"
get "r=portal&t=$TOKEN" > /dev/null
contiene "Portal: recibimos tu comprobante" "$TMP/pag.html" "Recibimos tu comprobante"
PR=$(sql "SELECT id FROM pagos WHERE evento_id = $EV AND estado = 'por_revisar'")
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Aparece en comprobantes por revisar" "$TMP/pag.html" "Comprobantes por revisar (1)"
contiene "Se ve la foto" "$TMP/pag.html" "r=archivo&amp;tipo=comprobante&amp;id=$PR"
check "La foto se abre (con sesión)" "200" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/?r=archivo&tipo=comprobante&id=$PR")"
get "r=cobros" > /dev/null
contiene "También en el tablero de cobros" "$TMP/pag.html" "Comprobantes por revisar (1)"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=confirmar" --data-urlencode "pago_id=$PR" --data-urlencode "referencia=PICH-777")
check "Comprobante confirmado" "confirmado" "$(sql "SELECT estado FROM pagos WHERE id = $PR")"
check "Con número de recibo" "1" "$(sql "SELECT count(*) FROM pagos WHERE id = $PR AND numero_recibo IS NOT NULL AND referencia = 'PICH-777'")"
r=$(subir_comprobante "$TOKEN" "100" "deuna")
PR2=$(sql "SELECT id FROM pagos WHERE evento_id = $EV AND estado = 'por_revisar'")
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=rechazar" --data-urlencode "pago_id=$PR2" --data-urlencode "motivo=No llegó a la cuenta")
check "Comprobante rechazado" "rechazado" "$(sql "SELECT estado FROM pagos WHERE id = $PR2")"
get "r=portal&t=$TOKEN" > /dev/null
contiene "El cliente ve que no se pudo validar" "$TMP/pag.html" "No se pudo validar"
r=$(subir_comprobante "$TOKEN" "99999" "transferencia")
get "r=portal&t=$TOKEN" > /dev/null
contiene "Comprobante mayor al saldo: error" "$TMP/pag.html" "mayor que lo que falta pagar"

echo "8. Payphone simulado (cajita y retorno)"
PP_FALSO=$((PUERTO+5)); APP2=$((PUERTO+6))
cat > "$TMP/payphone_falso.php" <<'PHP'
<?php
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok-web') { http_response_code(401); echo '{}'; exit; }
$b = json_decode((string) file_get_contents('php://input'), true) ?: [];
$id = (int) ($b['id'] ?? 0);
if ($id === 1) { echo json_encode(['transactionStatus' => 'Canceled']); exit; }
echo json_encode(['transactionStatus' => 'Approved', 'amount' => $id, 'clientTransactionId' => $b['clientTxId'] ?? '', 'transactionId' => 777]);
PHP
php -S 127.0.0.1:$PP_FALSO "$TMP/payphone_falso.php" > "$TMP/pp_falso.log" 2>&1 &
PID_PP=$!
TURENTA_DB_NAME=$DB TURENTA_DB_USER=turenta TURENTA_DB_PASS=$PGPASSWORD TURENTA_URL=$BASE PAYPHONE_API_BASE="http://127.0.0.1:$PP_FALSO" \
  php -S 127.0.0.1:$APP2 -t public tests/router.php > "$TMP/servidor_30b.log" 2>&1 &
PID_APP2=$!
sleep 1
sql "UPDATE negocios SET payphone_token = 'tok-web', payphone_store_id = 'tienda-web' WHERE id = $NID1" > /dev/null
get "r=portal&t=$TOKEN" > /dev/null
contiene "Con claves Payphone: botón de tarjeta" "$TMP/pag.html" "Pagar con tarjeta"
r=$(post_desde "r=portal&t=$TOKEN" "r=portal_pagar&t=$TOKEN&accion=tarjeta" --data-urlencode "monto=50")
check "Cajita de Payphone (200)" "200 " "$r"
contiene "Carga la cajita oficial" "$TMP/post.html" "payphone-payment-box.js"
contiene "Con la URL de respuesta" "$TMP/post.html" "\"responseUrl\":\"$BASE/?r=payphone\""
contiene "Monto en centavos" "$TMP/post.html" '"amount":5000'
TX=$(sql "SELECT referencia FROM pagos WHERE evento_id = $EV AND estado = 'pendiente' AND metodo = 'payphone' ORDER BY id DESC LIMIT 1")
PAGADO_ANTES=$(sql "SELECT COALESCE(sum(monto),0) FROM pagos WHERE evento_id = $EV AND tipo = 'evento' AND estado = 'confirmado'")
r=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code} %{redirect_url}" "http://127.0.0.1:$APP2/?r=payphone&id=5000&clientTransactionId=$TX")
check "Payphone aprobado vuelve al portal" "302 http://127.0.0.1:$APP2/?r=portal&t=$TOKEN#pagos" "$r"
check "Pago con tarjeta confirmado" "confirmado" "$(sql "SELECT estado FROM pagos WHERE referencia = '$TX'")"
check "Suma 50 a lo pagado" "$(sql "SELECT $PAGADO_ANTES + 50")" "$(sql "SELECT sum(monto) FROM pagos WHERE evento_id = $EV AND tipo = 'evento' AND estado = 'confirmado'")"
get "r=portal&t=$TOKEN" > /dev/null
contiene "Aviso: pago aprobado" "$TMP/pag.html" "Pago con tarjeta aprobado"
r=$(post_desde "r=portal&t=$TOKEN" "r=portal_pagar&t=$TOKEN&accion=tarjeta" --data-urlencode "monto=20")
TX2=$(sql "SELECT referencia FROM pagos WHERE evento_id = $EV AND estado = 'pendiente' AND metodo = 'payphone' ORDER BY id DESC LIMIT 1")
r=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "%{http_code}" "http://127.0.0.1:$APP2/?r=payphone&id=1&clientTransactionId=$TX2")
check "Payphone cancelado: el pago queda anulado" "anulado" "$(sql "SELECT estado FROM pagos WHERE referencia = '$TX2'")"
get "r=portal&t=$TOKEN" > /dev/null
contiene "Aviso: cancelaste el pago" "$TMP/pag.html" "Cancelaste el pago con tarjeta"
check "Payphone con código inventado: 400" "400" "$(curl -s -o "$TMP/pag.html" -w '%{http_code}' "$BASE/?r=payphone&id=5&clientTransactionId=TE999999-abcdef12")"
contiene "Mensaje amable" "$TMP/pag.html" "No reconocimos este pago"
r=$(post_desde "r=portal&t=$TOKEN" "r=portal_pagar&t=$TOKEN&accion=tarjeta" --data-urlencode "monto=999999")
get "r=portal&t=$TOKEN" > /dev/null
contiene "Tarjeta por más del saldo: error" "$TMP/pag.html" "El valor a pagar debe ser entre"
kill $PID_PP $PID_APP2 2>/dev/null
if grep -qiE "fatal|warning|deprecated|notice" "$TMP/servidor_30b.log"; then
  FALLAS=$((FALLAS+1)); echo "  FALLA avisos de PHP en el segundo servidor:"; grep -iE "fatal|warning|deprecated|notice" "$TMP/servidor_30b.log" | head -5
fi

echo "9. Garantía y gastos del evento"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=garantia_monto" --data-urlencode "garantia=200")
check "Garantía pedida 200" "200.00" "$(sql "SELECT garantia FROM eventos WHERE id = $EV")"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=garantia_recibir" --data-urlencode "monto=200" --data-urlencode "metodo=efectivo")
check "Garantía recibida" "200.00" "$(sql "SELECT sum(monto) FROM pagos WHERE evento_id = $EV AND tipo = 'garantia' AND estado = 'confirmado'")"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=garantia_retener" --data-urlencode "monto=30")
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Retener sin decir qué se dañó: error" "$TMP/pag.html" "Escribe qué se dañó"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=garantia_retener" --data-urlencode "monto=30" --data-urlencode "notas=2 copas rotas")
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=garantia_devolver" --data-urlencode "monto=170" --data-urlencode "metodo=transferencia")
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Garantía: queda 0 en manos del local" "$TMP/pag.html" "<dt>En manos del local</dt><dd><strong>\$0,00</strong>"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=gasto" --data-urlencode "concepto=Flores de la ceremonia" \
     --data-urlencode "monto=250,50" --data-urlencode "categoria=decoracion" --data-urlencode "fecha=$(date +%F)")
check "Gasto registrado" "250.50" "$(sql "SELECT monto FROM gastos WHERE evento_id = $EV")"
GID=$(sql "SELECT id FROM gastos WHERE evento_id = $EV")
get "r=evento_pagos&id=$EV" > /dev/null
contiene "Gasto en la lista" "$TMP/pag.html" "Flores de la ceremonia"
contiene "Ganancia real con el gasto" "$TMP/pag.html" "Ganancia real"
contiene "Costo estimado de la cotización" "$TMP/pag.html" "−\$1.200,00"
r=$(post_desde "r=evento_pagos&id=$EV" "r=evento_pagos&id=$EV&accion=gasto_borrar" --data-urlencode "gasto_id=$GID")
check "Gasto borrado" "0" "$(sql "SELECT count(*) FROM gastos WHERE evento_id = $EV")"

echo "10. Tablero de cobros y mensajes de hoy"
sql "UPDATE cuotas SET vence = CURRENT_DATE - 3 WHERE evento_id = $EV AND numero = 2" > /dev/null
check "Tablero de cobros" "200" "$(get "r=cobros")"
contiene "Cobrado este mes" "$TMP/pag.html" "Cobrado en"
contiene "Cuota vencida en el tablero" "$TMP/pag.html" "Segunda cuota"
contiene "Dice hace cuánto venció" "$TMP/pag.html" "venció hace 3 días"
contiene "WhatsApp de recordatorio" "$TMP/pag.html" "wa.me/593991234567"
contiene "Botón registrar pago rápido" "$TMP/pag.html" "Registrar pago"
check "Filtro de 7 días" "200" "$(get "r=cobros&dias=7")"
r=$(post_desde "r=cobros" "r=cobros&accion=pago" --data-urlencode "evento_id=$EV" --data-urlencode "monto=100" --data-urlencode "metodo=efectivo")
check "Pago rápido desde el tablero" "302 $BASE/?r=cobros" "$r"
check "Pago rápido guardado" "1" "$(sql "SELECT count(*) FROM pagos WHERE evento_id = $EV AND monto = 100 AND metodo = 'efectivo' AND estado = 'confirmado'")"
check "Mensajes de hoy" "200" "$(get "r=mensajes")"
contiene "Sección de cuotas" "$TMP/pag.html" "Cuotas que vencen o ya vencieron"
contiene "Mensaje con el enlace del portal" "$TMP/pag.html" "r%3Dportal%26t%3D$TOKEN"
CU=$(sql "SELECT id FROM cuotas WHERE evento_id = $EV AND numero = 2")
r=$(post_desde "r=mensajes" "r=mensajes&accion=enviado" --data-urlencode "tipo=cuota" --data-urlencode "ref_id=$CU")
check "Marcar como enviado" "1" "$(sql "SELECT count(*) FROM mensajes_enviados WHERE negocio_id = $NID1 AND tipo = 'cuota' AND ref_id = $CU")"
check "cuotas.aviso_enviado = hoy" "$(date +%F)" "$(sql "SELECT aviso_enviado FROM cuotas WHERE id = $CU")"
get "r=mensajes" > /dev/null
no_contiene "Ya no sale en la lista" "$TMP/pag.html" "Cuotas que vencen o ya vencieron"
contiene "Sale en ya enviados hoy" "$TMP/pag.html" "Ya enviados hoy (1)"
r=$(post_desde "r=mensajes" "r=mensajes&accion=deshacer" --data-urlencode "tipo=cuota" --data-urlencode "ref_id=$CU")
get "r=mensajes" > /dev/null
contiene "Deshacer: vuelve a la lista" "$TMP/pag.html" "Cuotas que vencen o ya vencieron"

echo "11. Plan Salón (pagado): portal sin los módulos de Quinta"
registrar salon "Salón Básico $RANDOM"
pagar_plan salon
EVS=$(crear_evento_sql "$(date -d "+40 days" +%F)" separado)
TOKS=$(sql "SELECT token_cliente FROM eventos WHERE id = $EVS")
check "Portal del plan Salón" "200" "$(get "r=portal&t=$TOKS")"
contiene "Tiene pagos" "$TMP/pag.html" "Tus pagos"
no_contiene "Sin invitados" "$TMP/pag.html" "Tus invitados"
no_contiene "Sin cronograma" "$TMP/pag.html" "Cronograma del evento"
sql "UPDATE eventos SET estado = 'cancelado', motivo_perdida = 'Prueba' WHERE id = $EVS" > /dev/null
get "r=portal&t=$TOKS" > /dev/null
contiene "Evento cancelado: aviso" "$TMP/pag.html" "Este evento fue cancelado"

echo "12. Permisos"
check "Otro negocio no ve los pagos de mi evento" "404" "$(get "r=evento_pagos&id=$EV")"
contiene "Mensaje: no se encontró" "$TMP/pag.html" "No se encontró el evento"
check "Otro negocio no saca mi recibo (no es PDF)" "200 text/html; charset=UTF-8" "$(pdf_get "r=pdf&tipo=recibo&id=$P2")"
check "Otro negocio no ve la foto del comprobante" "404" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/?r=archivo&tipo=comprobante&id=$PR")"
r=$(post_desde "r=cobros" "r=evento_pagos&id=$EV&accion=pago" --data-urlencode "monto=10" --data-urlencode "metodo=efectivo")
check "Otro negocio no registra pagos en mi evento" "0" "$(sql "SELECT count(*) FROM pagos WHERE evento_id = $EV AND monto = 10")"
get "r=cobros" > /dev/null
no_contiene "El tablero del otro negocio no muestra mis cuotas" "$TMP/pag.html" "Segunda cuota"
HASH=$(php -r 'echo password_hash("claveSegura1", PASSWORD_DEFAULT);')
COORD="coord$RANDOM@prueba.ec"; CONSULTA="consulta$RANDOM@prueba.ec"
sql "INSERT INTO usuarios (negocio_id, nombre, email, password_hash, rol) VALUES ($NID1, 'Coordinadora', '$COORD', '$HASH', 'coordinador'), ($NID1, 'Solo Mira', '$CONSULTA', '$HASH', 'consulta')" > /dev/null
entrar "$COORD" "claveSegura1"
check "Coordinador no entra a cobros" "302" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/?r=cobros")"
check "Coordinador no entra a los pagos del evento" "302" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/?r=evento_pagos&id=$EV")"
check "Coordinador no entra a mensajes" "302" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/?r=mensajes")"
check "Coordinador no saca recibos" "403" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/?r=pdf&tipo=recibo&id=$P2")"
entrar "$CONSULTA" "claveSegura1"
check "Consulta ve los pagos" "200" "$(get "r=evento_pagos&id=$EV")"
no_contiene "Consulta no ve el formulario de pago" "$TMP/pag.html" "Registrar un pago recibido"
check "Consulta no puede registrar pagos" "403" "$(post_desde "r=mi_cuenta" "r=evento_pagos&id=$EV&accion=pago" --data-urlencode "monto=10" --data-urlencode "metodo=efectivo" | cut -d' ' -f1)"
check "Consulta: el pago no se guardó" "0" "$(sql "SELECT count(*) FROM pagos WHERE evento_id = $EV AND monto = 10")"
check "Consulta ve el tablero de cobros" "200" "$(get "r=cobros")"
no_contiene "Consulta sin pago rápido" "$TMP/pag.html" "data-cb-abrir"
