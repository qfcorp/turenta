# Acceso: pedir demo (no hay registro abierto), entrar, salir, páginas públicas básicas
echo "1. Pedir demo (nada se da gratis) y acceso"
check "Página de entrar" "200" "$(get "r=login")"
contiene "Dice TuRenta" "$TMP/pag.html" "TuRenta"
contiene "Entrar invita a solicitar demo" "$TMP/pag.html" "Solicitar demo"
no_contiene "Entrar no ofrece nada gratis" "$TMP/pag.html" "gratis"
check "Solicitar demo (formulario)" "200" "$(get "r=registro")"
contiene "Formulario de pedido de demo" "$TMP/pag.html" "Solicitar demo"
contiene "Pide los módulos que le interesan" "$TMP/pag.html" 'value="invitados"'
no_contiene "Sin contraseña (no crea cuenta)" "$TMP/pag.html" 'name="clave"'
no_contiene "Sin prueba gratis" "$TMP/pag.html" "gratis"
NEG0=$(sql "SELECT count(*) FROM negocios")
r=$(post "r=registro" --data-urlencode "negocio=Hacienda Quiere Demo" --data-urlencode "nombre=Carla Demo" --data-urlencode "ciudad=Cuenca" \
     --data-urlencode "whatsapp=0991234567" --data-urlencode "email=carla.demo@prueba.ec" --data-urlencode "necesita=Tenemos 2 jardines" \
     --data-urlencode "modulos[]=invitados" --data-urlencode "modulos[]=plano")
contiene "Pedido recibido" "$TMP/post.html" "Recibimos tu pedido"
check "Pedido guardado (nueva, con sus módulos)" "nueva|invitados,plano|Cuenca" "$(sql "SELECT estado||'|'||modulos||'|'||ciudad FROM pedidos_demo WHERE email='carla.demo@prueba.ec'")"
check "No se creó ninguna cuenta" "$NEG0" "$(sql "SELECT count(*) FROM negocios")"
check "Sin sesión después de pedir" "302" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/?r=inicio")"
r=$(post "r=registro" --data-urlencode "negocio=X" --data-urlencode "nombre=X" --data-urlencode "whatsapp=1" --data-urlencode "email=malo")
contiene "Pedido con errores muestra el mensaje" "$TMP/post.html" "El correo no es válido"
r=$(post "r=registro" --data-urlencode "negocio=Robot SA" --data-urlencode "nombre=Robot" --data-urlencode "whatsapp=0991234567" --data-urlencode "email=robot@x.ec" --data-urlencode "sitio_web=http://spam")
check "Trampa para robots: no guarda" "0" "$(sql "SELECT count(*) FROM pedidos_demo WHERE email='robot@x.ec'")"
registrar quinta "Hacienda La Prueba"
check "Se creó el negocio" "1" "$(sql "SELECT count(*) FROM negocios WHERE id=$NID")"
check "Plan quinta" "quinta" "$(sql "SELECT plan_codigo FROM negocios WHERE id=$NID")"
check "Datos de ejemplo: 2 espacios" "2" "$(sql "SELECT count(*) FROM espacios WHERE negocio_id=$NID")"
get "r=salir" > /dev/null
check "Sin sesión, una página privada lleva a entrar" "302" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/?r=mas")"
r=$(post "r=login" --data-urlencode "email=$EMAIL" --data-urlencode "clave=mala")
contiene "Contraseña mala" "$TMP/post.html" "no coinciden"
r=$(post "r=login" --data-urlencode "email=$EMAIL" --data-urlencode "clave=claveSegura1")
check "Entrar lleva al inicio" "302 $BASE/?r=inicio" "$r"
check "Política de privacidad" "200" "$(get "r=privacidad")"
contiene "Privacidad habla de eventos" "$TMP/pag.html" "quintas"
check "Ruta que no existe: 404" "404" "$(get "r=no_existe")"
check "Menú Más" "200" "$(get "r=mas")"
contiene "Más lista Catálogo" "$TMP/pag.html" "Catálogo y precios"
check "Búsqueda rápida responde JSON" "200" "$(get "r=buscar&q=bo")"
contiene "JSON de búsqueda" "$TMP/pag.html" "resultados"
