import { createRouter, createWebHashHistory } from 'vue-router'
import { useSesion } from './stores/sesion'

export const router = createRouter({
  history: createWebHashHistory(), // en Electron, el hash evita problemas con file://
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('./views/Login.vue'),
      meta: { publica: true }
    },
    { path: '/', redirect: '/cursos' },
    { path: '/cursos', name: 'cursos', component: () => import('./views/Cursos.vue') },
    {
      path: '/cursos/:id',
      name: 'curso',
      component: () => import('./views/CursoDetalle.vue'),
      props: true
    },
    {
      path: '/cursos/:id/items',
      name: 'curso-items',
      component: () => import('./views/curso/Items.vue'),
      props: true
    },
    {
      path: '/cursos/:id/pruebas',
      name: 'curso-pruebas',
      component: () => import('./views/curso/Pruebas.vue'),
      props: true
    },
    {
      path: '/cursos/:id/diagnostico',
      name: 'curso-diagnostico',
      component: () => import('./views/curso/Diagnostico.vue'),
      props: true
    },
    // Material del curso que consulta el agente (RAG local)
    {
      path: '/cursos/:id/materiales',
      name: 'curso-materiales',
      component: () => import('./views/curso/Materiales.vue'),
      props: true
    },
    {
      path: '/cursos/:id/estudio',
      name: 'curso-estudio',
      component: () => import('./views/curso/Estudio.vue'),
      props: true
    },
    {
      path: '/cursos/:id/publicacion',
      name: 'curso-publicacion',
      component: () => import('./views/curso/Publicacion.vue'),
      props: true
    },
    // Etapa 6: seguimiento del grupo, ficha del estudiante y resultados
    {
      path: '/cursos/:id/seguimiento',
      name: 'curso-tablero',
      component: () => import('./views/curso/Tablero.vue'),
      props: true
    },
    {
      path: '/cursos/:id/estudiantes/:inscripcion',
      name: 'curso-estudiante',
      component: () => import('./views/curso/Estudiante.vue'),
      props: true
    },
    {
      path: '/cursos/:id/resultados',
      name: 'curso-resultados',
      component: () => import('./views/curso/Resultados.vue'),
      props: true
    },
    {
      path: '/admin/instructores',
      name: 'instructores',
      component: () => import('./views/Instructores.vue'),
      meta: { soloAdmin: true }
    }
  ]
})

router.beforeEach(async (to) => {
  const sesion = useSesion()
  if (!sesion.cargada) await sesion.cargar()
  if (!to.meta.publica && !sesion.usuario) return { name: 'login' }
  if (to.meta.soloAdmin && !sesion.esAdmin) return { name: 'cursos' }
  return true
})
