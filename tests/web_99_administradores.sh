# Administradores (migración 099) en pantalla: crear hasta 4, no cuentan en el plan, mismo menú y accesos que el dueño,
# no tocan al dueño ni a sí mismos, rol inválido rechazado y formularios con token CSRF.
planes_de_antes   # estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: web_112_planes.sh
clave_nueva() { get "r=usuarios" > /dev/null; grep -o 'class="clave-temporal">[^<]*' "$TMP/pag.html" | head -1 | sed 's/.*>//'; }
crear_usuario() { # nombre email rol
  post_desde "r=usuarios" "r=usuarios&accion=crear" --data-urlencode "nombre=$1" --data-urlencode "email=$2" --data-urlencode "rol=$3" > /dev/null
}
rutas_menu() { get "r=$1" > /dev/null; grep -o 'href="[^"]*r=[a-z_]*' "$TMP/pag.html" | sed 's/.*r=//' | sort -u | tr '\n' ' '; }

echo "1. Pantalla Usuarios: opción Administrador y contador"
registrar salon "Salón Admins $RANDOM"; pagar_plan salon; NA=$NID; EA=$EMAIL
DUENO_ID=$(sql "SELECT id FROM usuarios WHERE negocio_id=$NA AND rol='dueno'")
check "Usuarios abre" "200" "$(get "r=usuarios")"
contiene "Opción Administrador" "$TMP/pag.html" "Administrador (mismos permisos que el dueño)"
contiene "Contador de administradores" "$TMP/pag.html" "Administradores: 0 de 4"
contiene "Explica que no cuentan en el plan" "$TMP/pag.html" "no cuentan en el límite de usuarios de tu plan"
contiene "El formulario de crear lleva token CSRF" "$TMP/pag.html" 'name="csrf"'
FORMS=$(grep -c '<form method="post"' "$TMP/pag.html"); CSRFS=$(grep -o 'name="csrf"' "$TMP/pag.html" | wc -l | tr -d ' ')
check "Cada formulario de Usuarios lleva su token CSRF" "$FORMS" "$CSRFS"

echo "2. Crear administradores aunque el plan esté lleno"
crear_usuario "Vendedor Uno" "v1$RANDOM@admins.ec" vendedor
crear_usuario "Vendedor Dos" "v2$RANDOM@admins.ec" coordinador
check "Plan Salón lleno: 3 usuarios" "3" "$(sql "SELECT count(*) FROM usuarios WHERE negocio_id=$NA AND activo")"
get "r=usuarios" > /dev/null
contiene "Avisa del límite del plan" "$TMP/pag.html" "permite 3 usuarios activos"
contiene "…y que aún puede agregar administradores" "$TMP/pag.html" "Todavía puedes agregar administradores"
no_contiene "El botón Crear usuario sigue activo" "$TMP/pag.html" 'type="submit" disabled>Crear usuario'
A1="ana$RANDOM@admins.ec"
crear_usuario "Ana Administradora" "$A1" admin
check "Administrador creado" "admin" "$(sql "SELECT rol FROM usuarios WHERE email='$A1'")"
CLAVE_A1=$(clave_nueva)
check "Se muestra su clave temporal" "10" "${#CLAVE_A1}"
contiene "Contador: 1 de 4" "$TMP/pag.html" "Administradores: 1 de 4"
contiene "En la lista dice Administrador" "$TMP/pag.html" 'class="g-rol admin">Administrador'
A1_ID=$(sql "SELECT id FROM usuarios WHERE email='$A1'")
for n in 2 3 4; do crear_usuario "Admin $n" "a$n$RANDOM@admins.ec" admin; done
get "r=usuarios" > /dev/null
contiene "Contador: 4 de 4" "$TMP/pag.html" "Administradores: 4 de 4"
check "Los administradores no cuentan en el plan" "3" "$(sql "SELECT count(*) FROM usuarios WHERE negocio_id=$NA AND activo AND rol<>'admin'")"
check "Mi plan cuenta 3 usuarios (sin administradores)" "200" "$(get "r=planes")"
contiene "Mi plan: 3 usuarios activos" "$TMP/pag.html" "3</strong> usuarios activos\|y 3 usuarios activos"

echo "3. El quinto administrador no entra"
crear_usuario "Quinto" "quinto$RANDOM@admins.ec" admin
get "r=usuarios" > /dev/null
contiene "Mensaje del límite de administradores" "$TMP/pag.html" "Puede tener hasta 4 administradores además del dueño"
check "Siguen 4 administradores" "4" "$(sql "SELECT count(*) FROM usuarios WHERE negocio_id=$NA AND rol='admin'")"
A2_ID=$(sql "SELECT id FROM usuarios WHERE negocio_id=$NA AND rol='admin' AND nombre='Admin 2'")
post_desde "r=usuarios" "r=usuarios&accion=activar" --data-urlencode "id=$A2_ID" --data-urlencode "activo=0" > /dev/null
check "Desactivar a un administrador" "f" "$(sql "SELECT activo FROM usuarios WHERE id=$A2_ID")"
crear_usuario "Quinta Persona" "quinta$RANDOM@admins.ec" admin
check "Con un cupo libre entra otro" "4" "$(sql "SELECT count(*) FROM usuarios WHERE negocio_id=$NA AND rol='admin' AND activo")"
post_desde "r=usuarios" "r=usuarios&accion=activar" --data-urlencode "id=$A2_ID" --data-urlencode "activo=1" > /dev/null
get "r=usuarios" > /dev/null
contiene "Activar un quinto: mensaje" "$TMP/pag.html" "Puede tener hasta 4 administradores además del dueño"
check "No se activó" "f" "$(sql "SELECT activo FROM usuarios WHERE id=$A2_ID")"

echo "4. Rol inválido y CSRF"
for malo in jefe dueno ""; do
  crear_usuario "Rol Malo" "malo$RANDOM@admins.ec" "$malo"
done
get "r=usuarios" > /dev/null
contiene "Rol inválido: mensaje" "$TMP/pag.html" "Ese tipo de usuario no existe"
check "Rol inválido: no se guardó nada" "0" "$(sql "SELECT count(*) FROM usuarios WHERE nombre='Rol Malo'")"
V1_ID=$(sql "SELECT id FROM usuarios WHERE negocio_id=$NA AND nombre='Vendedor Uno'")
post_desde "r=usuarios" "r=usuarios&accion=rol" --data-urlencode "id=$V1_ID" --data-urlencode "rol=superjefe" > /dev/null
check "Cambiar a un rol inválido no cambia nada" "vendedor" "$(sql "SELECT rol FROM usuarios WHERE id=$V1_ID")"
SIN=$(curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code}" --data-urlencode "nombre=Sin Token" --data-urlencode "email=sintoken$RANDOM@admins.ec" --data-urlencode "rol=admin" "$BASE/?r=usuarios&accion=crear")
check "Sin token CSRF: 400" "400" "$SIN"
check "Sin token CSRF: no se guardó" "0" "$(sql "SELECT count(*) FROM usuarios WHERE nombre='Sin Token'")"

echo "5. El administrador tiene los mismos accesos que el dueño"
MENU_DUENO=$(rutas_menu inicio); MAS_DUENO=$(rutas_menu mas)
check "El menú del dueño trae Usuarios, Configuración y Mi plan" "3" "$(echo "$MENU_DUENO" | tr " " "\n" | grep -cx "usuarios\|configuracion\|planes")"
entrar "$A1" "$CLAVE_A1"
check "El administrador entra" "200" "$(get "r=inicio")"
check "Mismo menú que el dueño" "$MENU_DUENO" "$(rutas_menu inicio)"
check "Mismo «Más» que el dueño" "$MAS_DUENO" "$(rutas_menu mas)"
for r in usuarios configuracion planes metas reportes cobros espacios cuestionarios contrato puntaje_proveedores catalogo paquetes exportar\&tipo=pagos; do
  check "El administrador entra a $r" "200" "$(get "r=$r")"
done
get "r=configuracion" > /dev/null
contiene "Configuración: dice quién la ve" "$TMP/pag.html" "Solo el dueño y los administradores ven esta pantalla"
get "r=catalogo&nuevo=1" > /dev/null
contiene "Ve los costos como el dueño" "$TMP/pag.html" 'id="pcos"'

echo "6. El administrador no toca al dueño ni a sí mismo; sí a los demás"
get "r=usuarios" > /dev/null
no_contiene "Sin botones para el dueño" "$TMP/pag.html" "name=\"id\" value=\"$DUENO_ID\""
no_contiene "Sin botones para sí mismo" "$TMP/pag.html" "name=\"id\" value=\"$A1_ID\""
post_desde "r=usuarios" "r=usuarios&accion=activar" --data-urlencode "id=$DUENO_ID" --data-urlencode "activo=0" > /dev/null
get "r=usuarios" > /dev/null
contiene "No desactiva al dueño: mensaje" "$TMP/pag.html" "La cuenta del dueño no se puede cambiar"
post_desde "r=usuarios" "r=usuarios&accion=rol" --data-urlencode "id=$DUENO_ID" --data-urlencode "rol=consulta" > /dev/null
post_desde "r=usuarios" "r=usuarios&accion=clave" --data-urlencode "id=$DUENO_ID" > /dev/null
check "El dueño sigue intacto" "dueno|1" "$(sql "SELECT rol||'|'||activo::int FROM usuarios WHERE id=$DUENO_ID")"
check "Su clave no cambió" "t" "$(sql "SELECT clave_temporal = false FROM usuarios WHERE id=$DUENO_ID")"
post_desde "r=usuarios" "r=usuarios&accion=activar" --data-urlencode "id=$A1_ID" --data-urlencode "activo=0" > /dev/null
get "r=usuarios" > /dev/null
contiene "No se desactiva a sí mismo: mensaje" "$TMP/pag.html" "a ti mismo"
post_desde "r=usuarios" "r=usuarios&accion=rol" --data-urlencode "id=$A1_ID" --data-urlencode "rol=vendedor" > /dev/null
check "Sigue activo y de administrador" "admin|1" "$(sql "SELECT rol||'|'||activo::int FROM usuarios WHERE id=$A1_ID")"
post_desde "r=usuarios" "r=usuarios&accion=activar" --data-urlencode "id=$V1_ID" --data-urlencode "activo=0" > /dev/null
check "Desactiva a un usuario" "f" "$(sql "SELECT activo FROM usuarios WHERE id=$V1_ID")"
V2_ID=$(sql "SELECT id FROM usuarios WHERE negocio_id=$NA AND nombre='Vendedor Dos'")
post_desde "r=usuarios" "r=usuarios&accion=activar" --data-urlencode "id=$V2_ID" --data-urlencode "activo=0" > /dev/null
check "Desactiva a otro usuario" "f" "$(sql "SELECT activo FROM usuarios WHERE id=$V2_ID")"
A3_ID=$(sql "SELECT id FROM usuarios WHERE negocio_id=$NA AND nombre='Admin 3'")
post_desde "r=usuarios" "r=usuarios&accion=rol" --data-urlencode "id=$A3_ID" --data-urlencode "rol=consulta" > /dev/null
check "Cambia el rol de otro administrador" "consulta" "$(sql "SELECT rol FROM usuarios WHERE id=$A3_ID")"
get "r=usuarios" > /dev/null
contiene "Contador baja a 3 de 4" "$TMP/pag.html" "Administradores: 3 de 4"
A6="sexto$RANDOM@admins.ec"
crear_usuario "Creado por Ana" "$A6" admin
check "El administrador crea otro administrador" "admin" "$(sql "SELECT rol FROM usuarios WHERE email='$A6'")"

echo "7. Un vendedor no entra a Usuarios"
crear_usuario "Vende Tres" "v3$RANDOM@admins.ec" vendedor
CLAVE_V3=$(clave_nueva)
V3=$(sql "SELECT email FROM usuarios WHERE negocio_id=$NA AND nombre='Vende Tres'")
entrar "$V3" "$CLAVE_V3"
check "El vendedor no entra a usuarios" "403" "$(get "r=usuarios")"
check "El vendedor no entra a configuración" "403" "$(get "r=configuracion")"
entrar "$EA" claveSegura1
