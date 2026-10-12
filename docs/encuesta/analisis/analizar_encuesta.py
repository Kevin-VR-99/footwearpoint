#!/usr/bin/env python3
"""
TG-256 (R14) — Análisis estadístico de la encuesta de satisfacción por rol y módulo.

Lee el CSV que exporta Google Forms (ver ../guia-google-forms.md) y genera:
  - reporte.md con estadística descriptiva, NPS, módulos para mejora y alfa de Cronbach;
  - tablas CSV y gráficas PNG.

Solo usa pandas y matplotlib. No trae datos: el CSV se pasa como argumento.

Uso:
    python analizar_encuesta.py respuestas.csv [--salida salida] [--min-n 5]

Convenciones que espera del CSV (las fija la guía de Google Forms):
  - cada encabezado empieza con el ID de la pregunta: "G1. En general ...";
  - Likert: "1 - Totalmente en desacuerdo" ... "5 - Totalmente de acuerdo" o "No lo uso";
  - G5 (NPS): número de 0 a 10;
  - Xn.0 "¿Usas este módulo?": "Sí" / "No";
  - P0.1 (rol): "Administrador(a) de distribuidora", "Empleado(a) de distribuidora",
    "Cliente mayorista" o "Cliente".
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt  # noqa: E402
import pandas as pd  # noqa: E402

UMBRAL_PROMEDIO = 3.5
UMBRAL_PCT_BAJAS = 30.0

ROLES = {
    "admin": "Administrador(a) de distribuidora",
    "empleado": "Empleado(a) de distribuidora",
    "mayorista": "Cliente mayorista",
    "cliente": "Cliente",
}
ROLES_WEB = {"admin", "empleado"}
ROLES_APP = {"mayorista", "cliente"}

MODULOS = {
    "G": "General",
    "W1": "Catálogo y precios mayoristas",
    "W2": "Stock",
    "W3": "Punto de venta",
    "W4": "Pedidos y entregas",
    "W5": "Ciclo de compra y solicitud a fábrica",
    "W6": "Vales (web)",
    "W7": "Cambios de producto (web)",
    "W8": "Reportes",
    "W9": "Configuración, permisos y usuarios",
    "A1": "Acceso y cuenta",
    "A2": "Catálogo (app)",
    "A3": "Hacer un pedido",
    "A4": "Mis pedidos y pagos",
    "A5": "Vales (app)",
    "A6": "Cambios de producto (app)",
    "A7": "Notificaciones",
    "A8": "Mis clientes",
}
# Quién puede contestar cada módulo (además del filtro web/app).
SOLO_ROL = {"W9": {"admin"}, "A8": {"mayorista"}}

LIKERT_GENERAL = ["G1", "G2", "G3", "G4"]
NPS = "G5"
ABIERTAS_FIJAS = ["G6", "G7", "F1"]
ROL = "P0.1"

PATRON_ID = re.compile(r"^\s*([PGWAF]\d+(?:\.\d+)?)(?:[.\s]|$)")


# ----------------------------------------------------------------------------
# Lectura y limpieza
# ----------------------------------------------------------------------------

def leer_csv(ruta: Path) -> tuple[pd.DataFrame, dict[str, str]]:
    """Devuelve un DataFrame con columnas por ID de pregunta y el texto de cada una."""
    crudo = pd.read_csv(ruta, dtype=str, encoding="utf-8-sig", keep_default_na=False)
    por_id: dict[str, pd.Series] = {}
    textos: dict[str, str] = {}

    for col in crudo.columns:
        m = PATRON_ID.match(str(col))
        if not m:
            continue  # marca temporal, columnas de aviso, etc.
        qid = m.group(1)
        serie = crudo[col].replace("", pd.NA)
        # Si una pregunta sale dos veces (secciones duplicadas), se unen.
        por_id[qid] = por_id[qid].combine_first(serie) if qid in por_id else serie
        textos.setdefault(qid, re.sub(r"\.\d+$", "", str(col)).strip())

    if ROL not in por_id:
        sys.exit(f"Error: no encontré la columna del rol ({ROL}). Revisa que el título empiece con '{ROL}'.")

    return pd.DataFrame(por_id), textos


def normalizar_rol(valor) -> str | None:
    if pd.isna(valor):
        return None
    v = str(valor).strip().lower()
    if v.startswith("admin"):
        return "admin"
    if v.startswith("emplead"):
        return "empleado"
    if "mayorista" in v:
        return "mayorista"
    if v.startswith("cliente"):
        return "cliente"
    return None


def a_likert(valor) -> float:
    if pd.isna(valor):
        return float("nan")
    m = re.match(r"^\s*([1-5])(?:\s|-|$)", str(valor))
    return float(m.group(1)) if m else float("nan")  # "No lo uso" → NaN


def a_nps(valor) -> float:
    try:
        n = float(str(valor).strip())
    except (TypeError, ValueError):
        return float("nan")
    return n if 0 <= n <= 10 else float("nan")


def preguntas_likert(columnas) -> dict[str, list[str]]:
    """{módulo: [IDs Likert]} con lo que realmente trae el CSV."""
    bloques: dict[str, list[str]] = {"G": [q for q in LIKERT_GENERAL if q in columnas]}
    for mod in MODULOS:
        if mod == "G":
            continue
        ids = [q for q in (f"{mod}.1", f"{mod}.2") if q in columnas]
        if ids:
            bloques[mod] = ids
    return {k: v for k, v in bloques.items() if v}


def preparar(df: pd.DataFrame) -> tuple[pd.DataFrame, dict[str, list[str]], list[str]]:
    avisos: list[str] = []
    datos = pd.DataFrame(index=df.index)
    datos["rol"] = df[ROL].map(normalizar_rol)

    sin_rol = int(datos["rol"].isna().sum())
    if sin_rol:
        avisos.append(f"{sin_rol} respuesta(s) sin rol reconocible; se excluyen de los cortes por rol.")

    bloques = preguntas_likert(df.columns)
    for mod, ids in bloques.items():
        for q in ids:
            datos[q] = df[q].map(a_likert)

        if mod == "G":
            continue

        # "¿Usas este módulo?" = No → sus respuestas no cuentan.
        uso = f"{mod}.0"
        if uso in df.columns:
            no_usa = df[uso].fillna("").str.strip().str.lower().str.startswith("no")
            datos.loc[no_usa, ids] = float("nan")

        # Módulos que no corresponden al rol (p. ej. un cliente que contestó W1).
        permitidos = ROLES_WEB if mod.startswith("W") else ROLES_APP
        permitidos = permitidos & SOLO_ROL.get(mod, permitidos)
        fuera = datos["rol"].notna() & ~datos["rol"].isin(permitidos) & datos[ids].notna().any(axis=1)
        if fuera.any():
            avisos.append(f"{int(fuera.sum())} respuesta(s) de {mod} vinieron de un rol que no usa ese módulo; se ignoran.")
            datos.loc[fuera, ids] = float("nan")

    if NPS in df.columns:
        datos[NPS] = df[NPS].map(a_nps)

    for q in df.columns:
        if q in ABIERTAS_FIJAS or re.fullmatch(r"[WA]\d+\.3", q):
            datos[q] = df[q]

    return datos, bloques, avisos


# ----------------------------------------------------------------------------
# Estadística
# ----------------------------------------------------------------------------

def describir(valores: pd.Series) -> dict:
    v = valores.dropna()
    n = int(v.size)
    return {
        "n": n,
        "promedio": round(float(v.mean()), 2) if n else None,
        "desviacion": round(float(v.std(ddof=1)), 2) if n > 1 else None,
        "pct_1_2": round(float((v <= 2).mean() * 100), 1) if n else None,
    }


def por_pregunta(datos, bloques, textos) -> pd.DataFrame:
    filas = []
    for mod, ids in bloques.items():
        for q in ids:
            filas.append({"pregunta": q, "modulo": mod, "texto": textos.get(q, q), "rol": "Todos", **describir(datos[q])})
            for rol in ROLES:
                sub = datos.loc[datos["rol"] == rol, q]
                if sub.notna().any():
                    filas.append({"pregunta": q, "modulo": mod, "texto": textos.get(q, q), "rol": ROLES[rol], **describir(sub)})
    return pd.DataFrame(filas)


def marcar(fila: dict, min_n: int) -> str:
    if not fila["n"]:
        return ""
    mejora = (fila["promedio"] is not None and fila["promedio"] < UMBRAL_PROMEDIO) or (
        fila["pct_1_2"] is not None and fila["pct_1_2"] > UMBRAL_PCT_BAJAS
    )
    if not mejora:
        return "No"
    return "Sí" if fila["personas"] >= min_n else "Sí (pocos datos)"


def por_modulo(datos, bloques, min_n: int) -> pd.DataFrame:
    """Junta todas las respuestas Likert del módulo (cada respuesta a cada ítem cuenta)."""
    filas = []
    for mod, ids in bloques.items():
        cortes = [("Todos", datos)] + [(ROLES[r], datos[datos["rol"] == r]) for r in ROLES]
        for nombre, sub in cortes:
            apiladas = sub[ids].stack()
            if apiladas.empty:
                continue
            fila = {"modulo": mod, "nombre": MODULOS[mod], "rol": nombre,
                    "personas": int(sub[ids].notna().any(axis=1).sum()), **describir(apiladas)}
            fila["mejora"] = marcar(fila, min_n)
            filas.append(fila)
    return pd.DataFrame(filas)


def nps(serie: pd.Series) -> dict:
    v = serie.dropna()
    n = int(v.size)
    if not n:
        return {"n": 0, "promotores_pct": None, "pasivos_pct": None, "detractores_pct": None, "nps": None}
    prom = (v >= 9).mean() * 100
    detr = (v <= 6).mean() * 100
    return {
        "n": n,
        "promotores_pct": round(prom, 1),
        "pasivos_pct": round(100 - prom - detr, 1),
        "detractores_pct": round(detr, 1),
        "nps": round(prom - detr, 1),
    }


def cronbach(items: pd.DataFrame) -> tuple[float | None, int]:
    """Alfa de Cronbach con casos completos. None si no se puede calcular."""
    completos = items.dropna()
    k, n = completos.shape[1], completos.shape[0]
    if k < 2 or n < 3:
        return None, n
    var_total = completos.sum(axis=1).var(ddof=1)
    if not var_total or pd.isna(var_total):
        return None, n
    alfa = (k / (k - 1)) * (1 - completos.var(ddof=1).sum() / var_total)
    return round(float(alfa), 3), n


def interpretar_alfa(a: float | None) -> str:
    if a is None:
        return "No calculable (menos de 3 casos completos o sin variación)"
    if a >= 0.9:
        return "Excelente"
    if a >= 0.8:
        return "Buena"
    if a >= 0.7:
        return "Aceptable"
    if a >= 0.6:
        return "Cuestionable"
    if a >= 0.5:
        return "Pobre"
    return "Inaceptable"


# ----------------------------------------------------------------------------
# Gráficas
# ----------------------------------------------------------------------------

def graficar(datos, bloques, modulos: pd.DataFrame, salida: Path, aviso: str) -> list[str]:
    archivos = []
    todos = modulos[modulos["rol"] == "Todos"].copy()

    # 1. Promedio por módulo con el umbral de mejora.
    if not todos.empty:
        fig, ax = plt.subplots(figsize=(9, 0.4 * len(todos) + 1.5))
        etiquetas = [f"{m} {n}" for m, n in zip(todos["modulo"], todos["nombre"])]
        colores = ["#c0392b" if str(x).startswith("Sí") else "#2e86c1" for x in todos["mejora"]]
        ax.barh(etiquetas, todos["promedio"], color=colores)
        ax.axvline(UMBRAL_PROMEDIO, color="gray", linestyle="--", label=f"Umbral {UMBRAL_PROMEDIO}")
        ax.set_xlim(1, 5)
        ax.invert_yaxis()
        ax.set_xlabel("Promedio (1–5)")
        ax.set_title(f"Promedio por módulo (rojo = para mejora){aviso}")
        ax.legend(loc="lower right")
        fig.tight_layout()
        fig.savefig(salida / "promedio_por_modulo.png", dpi=120)
        plt.close(fig)
        archivos.append("promedio_por_modulo.png")

    # 2. Distribución de respuestas 1–5 por módulo.
    dist = {}
    for mod, ids in bloques.items():
        apiladas = datos[ids].stack()
        if not apiladas.empty:
            dist[mod] = apiladas.value_counts(normalize=True).reindex([1.0, 2.0, 3.0, 4.0, 5.0], fill_value=0) * 100
    if dist:
        tabla = pd.DataFrame(dist).T
        fig, ax = plt.subplots(figsize=(9, 0.4 * len(tabla) + 1.5))
        izquierda = pd.Series(0.0, index=tabla.index)
        paleta = ["#c0392b", "#e67e22", "#bdc3c7", "#5dade2", "#1e8449"]
        for valor, color in zip(tabla.columns, paleta):
            ax.barh(tabla.index, tabla[valor], left=izquierda, color=color, label=str(int(valor)))
            izquierda += tabla[valor]
        ax.invert_yaxis()
        ax.set_xlabel("% de respuestas")
        ax.set_title(f"Distribución de respuestas por módulo{aviso}")
        ax.legend(title="Respuesta", bbox_to_anchor=(1.01, 1), loc="upper left")
        fig.tight_layout()
        fig.savefig(salida / "distribucion_por_modulo.png", dpi=120)
        plt.close(fig)
        archivos.append("distribucion_por_modulo.png")

    # 3. Mapa módulo × rol.
    pivote = modulos[modulos["rol"] != "Todos"].pivot_table(index="modulo", columns="rol", values="promedio")
    if not pivote.empty:
        pivote = pivote.reindex(columns=[r for r in ROLES.values() if r in pivote.columns])
        fig, ax = plt.subplots(figsize=(8, 0.4 * len(pivote) + 1.8))
        im = ax.imshow(pivote.values, cmap="RdYlGn", vmin=1, vmax=5, aspect="auto")
        ax.set_xticks(range(len(pivote.columns)), pivote.columns, rotation=20, ha="right")
        ax.set_yticks(range(len(pivote.index)), pivote.index)
        for i in range(pivote.shape[0]):
            for j in range(pivote.shape[1]):
                v = pivote.values[i, j]
                if pd.notna(v):
                    ax.text(j, i, f"{v:.1f}", ha="center", va="center", fontsize=8)
        fig.colorbar(im, ax=ax, label="Promedio")
        ax.set_title(f"Promedio por módulo y rol{aviso}")
        fig.tight_layout()
        fig.savefig(salida / "modulo_por_rol.png", dpi=120)
        plt.close(fig)
        archivos.append("modulo_por_rol.png")

    # 4. NPS.
    if NPS in datos and datos[NPS].notna().any():
        conteo = datos[NPS].value_counts().reindex(range(11), fill_value=0)
        colores = ["#c0392b"] * 7 + ["#bdc3c7"] * 2 + ["#1e8449"] * 2
        fig, ax = plt.subplots(figsize=(8, 4))
        ax.bar(conteo.index, conteo.values, color=colores)
        ax.set_xticks(range(11))
        ax.set_xlabel("¿Qué tan probable es que recomiendes FootwearPoint? (0–10)")
        ax.set_ylabel("Respuestas")
        ax.set_title(f"NPS: detractores (rojo), pasivos (gris), promotores (verde){aviso}")
        fig.tight_layout()
        fig.savefig(salida / "nps.png", dpi=120)
        plt.close(fig)
        archivos.append("nps.png")

    return archivos


# ----------------------------------------------------------------------------
# Reporte
# ----------------------------------------------------------------------------

def tabla_md(df: pd.DataFrame, columnas: dict[str, str]) -> str:
    if df.empty:
        return "_Sin datos._\n"
    sub = df[list(columnas)].rename(columns=columnas)
    encabezado = "| " + " | ".join(sub.columns) + " |"
    separador = "|" + "|".join("---" for _ in sub.columns) + "|"
    filas = ["| " + " | ".join("—" if pd.isna(v) or v is None else str(v) for v in fila) + " |" for fila in sub.itertuples(index=False)]
    return "\n".join([encabezado, separador, *filas]) + "\n"


def reporte(ruta_csv, datos, textos, preguntas, modulos, nps_tabla, alfas, avisos, graficas, min_n, ficticio) -> str:
    out = []
    if ficticio:
        out.append("> ⚠️ **DATOS FICTICIOS DE EJEMPLO.** Este reporte se generó con un CSV inventado para probar el script. "
                   "No describe a usuarios reales y no debe usarse como resultado de la encuesta.\n")
    out.append("# Reporte de la encuesta de satisfacción\n")
    out.append(f"- Archivo: `{Path(ruta_csv).name}`")
    out.append(f"- Respuestas: **{len(datos)}**")
    conteo = datos["rol"].map(ROLES).value_counts()
    for nombre in ROLES.values():
        out.append(f"  - {nombre}: {int(conteo.get(nombre, 0))}")
    out.append(f"- Criterio de mejora: promedio < {UMBRAL_PROMEDIO} o más del {UMBRAL_PCT_BAJAS:.0f} % de respuestas 1–2. "
               f"Con menos de {min_n} personas se marca como \"pocos datos\".")
    out.append("- \"No lo uso\" y los módulos que la persona dijo no usar no cuentan en los promedios.\n")

    if avisos:
        out.append("## Avisos de limpieza\n")
        out.extend(f"- {a}" for a in avisos)
        out.append("")

    out.append("## 1. Módulos para mejora\n")
    marcados = modulos[modulos["mejora"].astype(str).str.startswith("Sí")]
    out.append(tabla_md(marcados, {"modulo": "Módulo", "nombre": "Nombre", "rol": "Rol", "personas": "Personas",
                                   "promedio": "Promedio", "pct_1_2": "% 1–2", "mejora": "Mejora"}))

    out.append("## 2. NPS (G5)\n")
    out.append(tabla_md(nps_tabla, {"rol": "Rol", "n": "n", "promotores_pct": "% promotores (9–10)",
                                    "pasivos_pct": "% pasivos (7–8)", "detractores_pct": "% detractores (0–6)", "nps": "NPS"}))

    out.append("## 3. Resumen por módulo\n")
    out.append(tabla_md(modulos, {"modulo": "Módulo", "nombre": "Nombre", "rol": "Rol", "personas": "Personas",
                                  "n": "n respuestas", "promedio": "Promedio", "desviacion": "Desv.", "pct_1_2": "% 1–2",
                                  "mejora": "Mejora"}))

    out.append("## 4. Fiabilidad: alfa de Cronbach por bloque\n")
    out.append("Casos completos de cada bloque. Los módulos tienen solo 2 preguntas Likert, así que el alfa es orientativo "
               "(con 2 ítems tiende a salir bajo); el bloque General (G1–G4) es el más informativo.\n")
    out.append(tabla_md(alfas, {"bloque": "Bloque", "nombre": "Nombre", "items": "Ítems", "n": "Casos completos",
                                "alfa": "Alfa", "interpretacion": "Interpretación"}))

    out.append("## 5. Detalle por pregunta\n")
    out.append(tabla_md(preguntas, {"pregunta": "ID", "rol": "Rol", "n": "n", "promedio": "Promedio",
                                    "desviacion": "Desv.", "pct_1_2": "% 1–2", "texto": "Pregunta"}))

    if graficas:
        out.append("## 6. Gráficas\n")
        out.extend(f"![{g}]({g})\n" for g in graficas)

    abiertas = [q for q in datos.columns if q in ABIERTAS_FIJAS or re.fullmatch(r"[WA]\d+\.3", q)]
    abiertas.sort(key=lambda q: (q[0] != "G", q[0] == "F", q))
    out.append("## 7. Respuestas abiertas\n")
    out.append("Para agruparlas por tema (error, falta función, confuso, lento) en `docs/encuesta/resultados.md`.\n")
    for q in abiertas:
        textos_q = [str(t).strip() for t in datos[q].dropna() if str(t).strip()]
        if textos_q:
            out.append(f"### {textos.get(q, q)} ({len(textos_q)})\n")
            out.extend(f"- {t}" for t in textos_q)
            out.append("")

    return "\n".join(out) + "\n"


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Analiza el CSV de la encuesta de satisfacción (TG-256).")
    parser.add_argument("csv", type=Path, help="CSV exportado de Google Forms")
    parser.add_argument("--salida", type=Path, default=Path("salida"), help="Carpeta de salida (por defecto ./salida)")
    parser.add_argument("--min-n", type=int, default=5, help="Personas mínimas para marcar mejora sin la nota 'pocos datos'")
    args = parser.parse_args(argv)

    if not args.csv.exists():
        sys.exit(f"Error: no existe {args.csv}")

    df, textos = leer_csv(args.csv)
    datos, bloques, avisos = preparar(df)
    ficticio = "FICTICI" in args.csv.name.upper() or any("FICTICI" in str(c).upper() for c in pd.read_csv(args.csv, nrows=0, encoding="utf-8-sig").columns)

    preguntas = por_pregunta(datos, bloques, textos)
    modulos = por_modulo(datos, bloques, args.min_n)

    nps_filas = [{"rol": "Todos", **nps(datos[NPS])}] if NPS in datos else []
    if NPS in datos:
        for rol, nombre in ROLES.items():
            fila = nps(datos.loc[datos["rol"] == rol, NPS])
            if fila["n"]:
                nps_filas.append({"rol": nombre, **fila})
    nps_tabla = pd.DataFrame(nps_filas)

    alfas = []
    for mod, ids in bloques.items():
        a, n = cronbach(datos[ids])
        alfas.append({"bloque": mod, "nombre": MODULOS[mod], "items": ", ".join(ids), "n": n, "alfa": a,
                      "interpretacion": interpretar_alfa(a)})
    alfas = pd.DataFrame(alfas)

    args.salida.mkdir(parents=True, exist_ok=True)
    aviso = "\n(DATOS FICTICIOS DE EJEMPLO)" if ficticio else ""
    graficas = graficar(datos, bloques, modulos, args.salida, aviso)

    preguntas.to_csv(args.salida / "por_pregunta.csv", index=False)
    modulos.to_csv(args.salida / "por_modulo.csv", index=False)
    nps_tabla.to_csv(args.salida / "nps.csv", index=False)
    alfas.to_csv(args.salida / "cronbach.csv", index=False)
    (args.salida / "reporte.md").write_text(
        reporte(args.csv, datos, textos, preguntas, modulos, nps_tabla, alfas, avisos, graficas, args.min_n, ficticio),
        encoding="utf-8",
    )

    print(f"Respuestas: {len(datos)} · módulos para mejora: "
          f"{int(modulos['mejora'].astype(str).str.startswith('Sí').sum())} (filas módulo×rol)")
    print(f"Listo: {args.salida / 'reporte.md'}")
    for a in avisos:
        print(f"Aviso: {a}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
