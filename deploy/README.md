# Konfigurace nasazení

## Struktura

```
deploy/
├── base/                          # Společné Kubernetes manifesty
│   ├── kustomization.yaml
│   ├── namespace.yaml
│   ├── configmap.yaml
│   ├── deployment.yaml
│   ├── service.yaml
│   └── ingress.yaml
└── overlays/
    ├── staging/                   # Staging-specifické úpravy
    │   ├── kustomization.yaml
    │   ├── patch-configmap.yaml
    │   ├── patch-deployment.yaml
    │   └── patch-ingress.yaml
    └── production/                # Production-specifické úpravy
        ├── kustomization.yaml
        ├── patch-deployment.yaml
        └── patch-ingress.yaml
```

## Kubeconfig

Staging a production používají **odlišné kubeconfig** soubory, které umožňují nasazení do různých Kubernetes clusterů.

### GitHub Actions secrets

V nastavení repozitáře (Settings → Secrets and variables → Actions) je nutné nakonfigurovat:

| Secret                   | Environment  | Popis                                        |
|--------------------------|-------------|----------------------------------------------|
| `KUBECONFIG_STAGING`     | staging     | Base64-encoded kubeconfig pro staging cluster |
| `KUBECONFIG_PRODUCTION`  | production  | Base64-encoded kubeconfig pro production cluster |

### Příprava kubeconfig secretu

```bash
# Staging
cat ~/.kube/staging-config | base64 -w 0
# Výstup vložte jako secret KUBECONFIG_STAGING

# Production
cat ~/.kube/production-config | base64 -w 0
# Výstup vložte jako secret KUBECONFIG_PRODUCTION
```

### GitHub Environments

Doporučujeme vytvořit dva GitHub environments:

- **staging** – nasazuje se automaticky při push do `main`
- **production** – nasazuje se pouze manuálně (workflow_dispatch), vyžaduje approval

## Manuální nasazení

```bash
# Staging
export KUBECONFIG=~/.kube/staging-config
kubectl apply -k deploy/overlays/staging

# Production
export KUBECONFIG=~/.kube/production-config
kubectl apply -k deploy/overlays/production
```

## Rozdíly mezi prostředími

| Parametr        | Staging                                    | Production                        |
|----------------|---------------------------------------------|-----------------------------------|
| Namespace      | `plavenky-staging`                          | `plavenky-production`             |
| Repliky        | 1                                           | 2                                 |
| CPU limit      | 100m                                        | 500m                              |
| Memory limit   | 128Mi                                       | 256Mi                             |
| Ingress host   | `staging-plavenky.example.com`              | `plavenky.example.com`            |
| TLS            | ne                                          | ano (cert-manager)                |
| Stats endpoint | staging-stats.apps2.r73.info                | app-stats.apps2.r73.info          |
| Kubeconfig     | `KUBECONFIG_STAGING` (GitHub secret)        | `KUBECONFIG_PRODUCTION` (GitHub secret) |
