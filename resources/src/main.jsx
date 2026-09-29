import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.jsx'
import { AuthProvider } from './context/AuthContext.jsx'
import { FeaturesProvider } from './context/FeaturesContext.jsx'
import { DialogoProvider } from './context/DialogoContext.jsx'
import { ToastProvider } from './context/ToastContext.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <FeaturesProvider>
          <ToastProvider>
            <DialogoProvider>
              <App />
            </DialogoProvider>
          </ToastProvider>
        </FeaturesProvider>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
