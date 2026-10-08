# Comprueba en R los estadísticos de la pestaña «Resultados» de la consola (criterio de la Etapa 6).
# 1) php artisan demo:resultados <curso> --n=30 --control=<otro curso> (escribe storage/app/demo-resultados.csv)
# 2) Rscript docs/validacion/estadisticas.R apps/web/storage/app/demo-resultados.csv
# Compara cada cifra con la consola: deben coincidir al menos en tres decimales.

args <- commandArgs(trailingOnly = TRUE)
d <- read.csv(if (length(args) > 0) args[1] else "apps/web/storage/app/demo-resultados.csv")
e <- subset(d, grupo == "experimental")
dif <- e$post_global - e$pre_global

cat("\n== Pre contra post (curso experimental), n =", nrow(e), "==\n")
print(shapiro.test(dif))                                      # normalidad de las diferencias
print(t.test(e$post_global, e$pre_global, paired = TRUE))     # t, gl, p e IC del 95 %
print(wilcox.test(e$post_global, e$pre_global, paired = TRUE)) # V y p (exacta si n < 50 sin empates)
cat("d_z =", round(mean(dif) / sd(dif), 4), "\n")
cat("g de Hake =", round((mean(e$post_global) - mean(e$pre_global)) / (100 - mean(e$pre_global)), 4), "\n")

if (any(d$grupo == "control")) {
  k <- subset(d, grupo == "control")
  cat("\n== Experimental contra control ==\n")
  print(t.test(e$post_global, k$post_global))                 # Welch (var.equal = FALSE)
  sp <- sqrt(((nrow(e) - 1) * var(e$post_global) + (nrow(k) - 1) * var(k$post_global)) / (nrow(e) + nrow(k) - 2))
  cat("d de Cohen (post) =", round((mean(e$post_global) - mean(k$post_global)) / sp, 4), "\n")

  d$grupo <- factor(d$grupo, levels = c("control", "experimental"))
  cat("\n== ANCOVA: post ~ pre + grupo ==\n")
  # F del grupo ajustado por el pre (igual a la suma de cuadrados tipo II o III, pues el modelo no tiene interacción)
  print(anova(lm(post_global ~ pre_global, data = d), lm(post_global ~ pre_global + grupo, data = d)))
  # Homogeneidad de pendientes: la interacción no debe ser significativa
  print(anova(lm(post_global ~ pre_global + grupo, data = d), lm(post_global ~ pre_global * grupo, data = d)))

  m <- lm(post_global ~ pre_global + grupo, data = d)
  ajustadas <- predict(m, newdata = data.frame(pre_global = mean(d$pre_global), grupo = levels(d$grupo)))
  print(setNames(round(ajustadas, 4), levels(d$grupo)))        # medias ajustadas
}
