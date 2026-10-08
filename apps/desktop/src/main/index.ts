import { app, BrowserWindow, shell } from 'electron'
import { join } from 'node:path'
import { desktopCapturer, session } from 'electron'
import { electronApp, is, optimizer } from '@electron-toolkit/utils'
import icon from '../../resources/icon.png?asset'
import { registrarIpc } from './ipc'
import { registrarIpcExportacion } from './exportacion'
import { iniciarActualizaciones } from './actualizaciones'
import { Almacen } from './diseno/almacen'
import { registrarIpcDiseno } from './diseno/ipc'

function crearVentana(): void {
  const ventana = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 1024,
    minHeight: 680,
    show: false,
    autoHideMenuBar: true,
    title: 'CLT4BP · Consola',
    ...(process.platform === 'linux' ? { icon } : {}),
    webPreferences: {
      preload: join(__dirname, '../preload/index.js'),
      sandbox: true,
      contextIsolation: true,
      nodeIntegration: false,
      webSecurity: true
    }
  })

  ventana.on('ready-to-show', () => ventana.show())

  // Enlaces externos: solo https y en el navegador del sistema
  ventana.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('https://')) shell.openExternal(url)
    return { action: 'deny' }
  })
  // La ventana nunca navega fuera de la interfaz empaquetada
  ventana.webContents.on('will-navigate', (evento, url) => {
    const permitido =
      is.dev &&
      process.env.ELECTRON_RENDERER_URL &&
      url.startsWith(process.env.ELECTRON_RENDERER_URL)
    if (!permitido) evento.preventDefault()
  })

  if (is.dev && process.env.ELECTRON_RENDERER_URL) {
    ventana.loadURL(process.env.ELECTRON_RENDERER_URL)
  } else {
    ventana.loadFile(join(__dirname, '../renderer/index.html'))
  }
}

app.whenReady().then(() => {
  electronApp.setAppUserModelId('mx.clt4bp.consola')
  app.on('browser-window-created', (_, w) => optimizer.watchWindowShortcuts(w))
  registrarIpc()
  registrarIpcExportacion()
  // Grabador de protocolos verbales: getDisplayMedia necesita que el proceso main elija la fuente
  session.defaultSession.setDisplayMediaRequestHandler(async (_peticion, responder) => {
    const [pantalla] = await desktopCapturer.getSources({ types: ['screen'] })
    responder({ video: pantalla })
  })
  // Solo se conceden los permisos que la consola usa (micrófono y captura de pantalla)
  session.defaultSession.setPermissionRequestHandler((_wc, permiso, responder) =>
    responder(permiso === 'media' || permiso === 'display-capture')
  )
  // Un archivo SQLite por usuario de Windows, en %APPDATA%\clt4bp-consola
  const almacen = new Almacen(join(app.getPath('userData'), 'clt4bp.db'))
  registrarIpcDiseno(almacen)
  app.on('will-quit', () => almacen.cerrar())
  crearVentana()
  iniciarActualizaciones() // Etapa 7
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) crearVentana()
  })
})

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit()
})
