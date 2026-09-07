import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.jsx'
import { AuthProvider } from './context/AuthContext.jsx'
import { FeaturesProvider } from './context/FeaturesContext.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <FeaturesProvider>
          <App />
        </FeaturesProvider>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
