import { useState } from 'react'
import { router } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Icon } from '@/components/icon'

interface DocumentRequestFormProps {
    documentTypes: Record<string, string>
    incomeReasons: string[]
}

interface FormData {
    tip: string
    cui: string
    an: string
    luna: string
    motiv: string
    numar_inregistrare: string
    cui_pui: string
}

export default function DocumentRequestForm({ documentTypes, incomeReasons }: DocumentRequestFormProps) {
    const [formData, setFormData] = useState<FormData>({
        tip: '',
        cui: '',
        an: '',
        luna: '',
        motiv: '',
        numar_inregistrare: '',
        cui_pui: '',
    })
    const [loading, setLoading] = useState(false)
    const [errors, setErrors] = useState<Record<string, string>>({})

    const handleInputChange = (field: keyof FormData, value: string) => {
        setFormData(prev => ({ ...prev, [field]: value }))
        if (errors[field]) {
            setErrors(prev => ({ ...prev, [field]: '' }))
        }
    }

    const requiresYear = (tip: string) => {
        return [
            'Bilant anual', 'Istoric declaratii', 'D205', 'D120', 'D130', 'D101', 
            'D392', 'D393', 'D106', 'Bilant semestrial', 'Adeverinte Venit', 'D212'
        ].includes(tip)
    }

    const requiresMonth = (tip: string) => {
        return [
            'D300', 'D390', 'D100', 'D112', 'D208', 'D394', 'D301', 'D180', 'D311'
        ].includes(tip)
    }

    const requiresReason = (tip: string) => {
        return tip === 'Adeverinte Venit'
    }

    const requiresRegistrationNumber = (tip: string) => {
        return tip === 'Duplicat Recipisa'
    }

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()
        setLoading(true)
        setErrors({})

        const submitData = {
            tip: formData.tip,
            cui: formData.cui,
            ...(formData.an && { an: parseInt(formData.an) }),
            ...(formData.luna && { luna: parseInt(formData.luna) }),
            ...(formData.motiv && { motiv: formData.motiv }),
            ...(formData.numar_inregistrare && { numar_inregistrare: formData.numar_inregistrare }),
            ...(formData.cui_pui && { cui_pui: formData.cui_pui }),
        }

        router.post('/spv/document-request', submitData, {
            preserveScroll: true,
            onSuccess: () => {
                setFormData({
                    tip: '',
                    cui: '',
                    an: '',
                    luna: '',
                    motiv: '',
                    numar_inregistrare: '',
                    cui_pui: '',
                })
            },
            onError: (errors) => {
                setErrors(errors as Record<string, string>)
            },
            onFinish: () => {
                setLoading(false)
            }
        })
    }

    const currentYear = new Date().getFullYear()
    const years = Array.from({ length: currentYear - 1999 }, (_, i) => currentYear - i)
    const months = [
        { value: '1', label: 'January' },
        { value: '2', label: 'February' },
        { value: '3', label: 'March' },
        { value: '4', label: 'April' },
        { value: '5', label: 'May' },
        { value: '6', label: 'June' },
        { value: '7', label: 'July' },
        { value: '8', label: 'August' },
        { value: '9', label: 'September' },
        { value: '10', label: 'October' },
        { value: '11', label: 'November' },
        { value: '12', label: 'December' },
    ]

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Icon name="FileText" className="h-5 w-5" />
                    New Document Request
                </CardTitle>
                <CardDescription>
                    Submit a request for documents from ANAF SPV
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Document Type */}
                        <div className="space-y-2">
                            <Label htmlFor="tip">Document Type *</Label>
                            <Select 
                                value={formData.tip} 
                                onValueChange={(value) => handleInputChange('tip', value)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select document type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(documentTypes).map(([key, description]) => (
                                        <SelectItem key={key} value={key}>
                                            <div>
                                                <div className="font-medium">{key}</div>
                                                <div className="text-sm text-muted-foreground">{description}</div>
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.tip && <p className="text-sm text-destructive">{errors.tip}</p>}
                        </div>

                        {/* CUI/CNP */}
                        <div className="space-y-2">
                            <Label htmlFor="cui">CUI/CNP *</Label>
                            <Input
                                id="cui"
                                value={formData.cui}
                                onChange={(e) => handleInputChange('cui', e.target.value)}
                                placeholder="Enter CUI or CNP"
                            />
                            {errors.cui && <p className="text-sm text-destructive">{errors.cui}</p>}
                        </div>

                        {/* Year - conditional */}
                        {(requiresYear(formData.tip) || requiresMonth(formData.tip)) && (
                            <div className="space-y-2">
                                <Label htmlFor="an">Year {requiresYear(formData.tip) ? '*' : ''}</Label>
                                <Select 
                                    value={formData.an} 
                                    onValueChange={(value) => handleInputChange('an', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select year" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {years.map(year => (
                                            <SelectItem key={year} value={year.toString()}>
                                                {year}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.an && <p className="text-sm text-destructive">{errors.an}</p>}
                            </div>
                        )}

                        {/* Month - conditional */}
                        {requiresMonth(formData.tip) && (
                            <div className="space-y-2">
                                <Label htmlFor="luna">Month *</Label>
                                <Select 
                                    value={formData.luna} 
                                    onValueChange={(value) => handleInputChange('luna', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select month" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {months.map(month => (
                                            <SelectItem key={month.value} value={month.value}>
                                                {month.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.luna && <p className="text-sm text-destructive">{errors.luna}</p>}
                            </div>
                        )}

                        {/* Reason - conditional */}
                        {requiresReason(formData.tip) && (
                            <div className="space-y-2 md:col-span-2">
                                <Label htmlFor="motiv">Reason *</Label>
                                <Select 
                                    value={formData.motiv} 
                                    onValueChange={(value) => handleInputChange('motiv', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select reason" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {incomeReasons.map(reason => (
                                            <SelectItem key={reason} value={reason}>
                                                {reason}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.motiv && <p className="text-sm text-destructive">{errors.motiv}</p>}
                            </div>
                        )}

                        {/* Registration Number - conditional */}
                        {requiresRegistrationNumber(formData.tip) && (
                            <div className="space-y-2">
                                <Label htmlFor="numar_inregistrare">Registration Number *</Label>
                                <Input
                                    id="numar_inregistrare"
                                    value={formData.numar_inregistrare}
                                    onChange={(e) => handleInputChange('numar_inregistrare', e.target.value)}
                                    placeholder="Enter registration number"
                                />
                                {errors.numar_inregistrare && <p className="text-sm text-destructive">{errors.numar_inregistrare}</p>}
                            </div>
                        )}

                        {/* PUI CUI - optional */}
                        <div className="space-y-2">
                            <Label htmlFor="cui_pui">PUI CUI (optional)</Label>
                            <Input
                                id="cui_pui"
                                value={formData.cui_pui}
                                onChange={(e) => handleInputChange('cui_pui', e.target.value)}
                                placeholder="Enter PUI CUI if applicable"
                            />
                            {errors.cui_pui && <p className="text-sm text-destructive">{errors.cui_pui}</p>}
                        </div>
                    </div>

                    {formData.tip && (
                        <Alert>
                            <Icon name="Info" className="h-4 w-4" />
                            <AlertDescription>
                                <strong>{formData.tip}:</strong> {documentTypes[formData.tip]}
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="flex justify-end">
                        <Button type="submit" disabled={loading || !formData.tip || !formData.cui}>
                            {loading ? (
                                <>
                                    <Icon name="Loader2" className="mr-2 h-4 w-4 animate-spin" />
                                    Submitting...
                                </>
                            ) : (
                                <>
                                    <Icon name="Send" className="mr-2 h-4 w-4" />
                                    Submit Request
                                </>
                            )}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    )
}