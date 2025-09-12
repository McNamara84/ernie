import SwaggerUI from 'swagger-ui';
import 'swagger-ui/dist/swagger-ui.css';

window.addEventListener('load', () => {
  SwaggerUI({
    spec: window.__spec__ ?? {},
    dom_id: '#swagger-ui',
  });
});
