import { app, dialog } from 'electron'
import { autoUpdater } from 'electron-updater'

const SEIS_HORAS = 6 * 60 * 60 * 1000

/**
 * Actualizaciones automáticas con electron-updater (proveedor «generic» de electron-builder.yml).
 * Revisa al abrir y cada 6 horas, descarga en segundo plano y se instala al cerrar la consola:
 * nunca interrumpe una grabación ni un diseño a medias.
 */
export function iniciarActualizaciones(): void {
  if (!app.isPackaged) return // en desarrollo no hay instalador que actualizar

  autoUpdater.autoDownload = true
  autoUpdater.autoInstallOnAppQuit = true
  autoUpdater.logger = console

  autoUpdater.on('update-downloaded', (info) => {
    void dialog.showMessageBox({
      type: 'info',
      title: 'Actualización lista',
      message: `La versión ${info.version} de la consola se instalará cuando la cierres.`,
      buttons: ['Entendido']
    })
  })
  autoUpdater.on('error', (e) => console.error('No se pudo revisar si hay actualizaciones:', e.message))

  const revisar = (): void => {
    autoUpdater.checkForUpdates().catch(() => undefined) // sin red: se intenta en la siguiente vuelta
  }
  revisar()
  setInterval(revisar, SEIS_HORAS)
}
