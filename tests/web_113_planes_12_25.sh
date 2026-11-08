# Precios de $12 y $25 en pantalla (migración 113, decisión de Dimitry del 6 nov 2026): Básico $12 con 3 módulos a elegir,
# cada módulo más $5, el Asistente IA $3 aparte; Completo $25 con todo.
sql "UPDATE planes SET precio_mensual=12, modulos_elegir=3, precio_modulo_extra=5 WHERE codigo='esencial'; UPDATE planes SET precio_mensual=25 WHERE codigo='quinta'" > /dev/null
sql "UPDATE modulos SET precio_mensual = CASE WHEN id='asistente_ia' THEN 3 ELSE 5 END" > /dev/null
echo "1. Básico pagado con 4 módulos y el Asistente IA: 3 incluidos, el cuarto \$5 y la IA \$3 = \$20"
registrar esencial "Local Básico Doce $RANDOM"; pagar_plan esencial; NB=$NID
sql "UPDATE negocios SET modulos_extra='paquetes,contratos,portal_cliente,plano,asistente_ia' WHERE id=$NB" > /dev/null
check "Mi plan abre" "200" "$(get "r=planes")"
contiene "Dice cuántos módulos trae a su elección" "$TMP/pag.html" "Tu plan trae <strong>3 módulos a tu elección</strong>"
contiene "El cuarto módulo: «Extra · \$5»" "$TMP/pag.html" "Extra · \$5,00 + IVA al mes"
contiene "La IA: «Extra · \$3»" "$TMP/pag.html" "Extra · \$3,00 + IVA al mes"
contiene "Total: \$12 + \$5 + \$3 = \$20" "$TMP/pag.html" '<p class="mp-precio">\$20,00'
contiene "Desglose: plan \$12" "$TMP/pag.html" "Plan \$12,00"
